<?php


namespace App\Modules\FlowerDrive\Repositories;


use App\BMarketOrgSettingDetails;
use App\BMarketSectionOrArea;
use App\ConsumerInvoice;
use App\ConsumerOrder;
use App\ConsumerOrderItemLocation;
use App\ConsumerPayment;
use App\CountryStates;
use App\EmailTemplate;
use App\FldSections;
use App\FlowerDrive;
use App\FlowerDriveContact;
use App\FlowerDriveContactSite;
use App\FlowerDriveLine;
use App\FlowerDriveSite;
use App\Jobs\FlowerDriveChunkEmailNewJob;
use App\Jobs\FlowerDriveProcessContactListJob;
use App\Mail\FldEmailConfirmation;
use App\Modules\Byondmarket\Contracts\OrganizationSettingsRepositoryInterface;
use App\Modules\Emailtemplate\Contracts\EmailTemplateRepositoryInterface;
use App\Modules\FlowerDrive\Contracts\ManageFlowerDriveRepositoryInterface;
use App\Modules\Person\Contracts\ContactListRepositoryInterface;
use App\Modules\Person\Http\Resources\ContactListResourcesCollection;
use App\Modules\Site\Contracts\SiteInterface;
use App\Repositories\MainRepository;
use App\Site;
use App\Territory;
use App\User;
use Barryvdh\Snappy\Facades\SnappyPdf as PDF;
use Carbon\Carbon;
use Illuminate\Contracts\Container\Container as App;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use function GuzzleHttp\json_decode;
use App\FlowerDrivePurchaseOrder;
use App\FlowerDriveByondMarketContact;
use App\FldPaperTemplatePdfChunk;
use App\FldPaperTemplateArchiveUrl;

class ManageFlowerDriveRepository extends MainRepository implements ManageFlowerDriveRepositoryInterface
{
    private $contactListRepo;
    private $organizationSettingsRepo;
    private $emailTemplateRepo;

    function model()
    {
        //return 'App\ConsumerOrder';

        return 'App\FlowerDrive';
    }
    public function __construct(App $app,  ContactListRepositoryInterface $contactListRepo,OrganizationSettingsRepositoryInterface $organizationSettingsRepo, EmailTemplateRepositoryInterface $emailTemplateRepo)
    {
        parent::__construct($app);
        $this->contactListRepo = $contactListRepo;
        $this->organizationSettingsRepo = $organizationSettingsRepo;
        $this->emailTemplateRepo = $emailTemplateRepo;
    }
    /**
     * @return mixed
     * get list of orders
     */
    public function getFlowerDriveList($options, $pluck = null)
    {
        $user = getLoggedInUser();

        if(isset($options['tenantId'])){
            $flowerDrives = FlowerDrive::select('FLD_TB.fld_id','fld_name','contact_count', 'FLD_TB.fld_type', DB::raw('DATE(activated_at) as activated_at'),
                DB::raw('DATE(published_at) as published_at'), DB::raw('DATE(start_date) as start_date'),
                DB::raw('DATE(order_by_date) as order_by_date'), 'fld_status',
                DB::raw('DATE(order_by_date) as order_by_date'),
                DB::raw('DATE(cancel_by_date) as cancel_by_date')
                ,'fld_desc','SPECIAL_OCCASSION_TB.occ_name','FLD_TB.org_id')
                ->where('FLD_TB.fld_type', $options['fldType'])
                ->where('FLD_TB.org_id', $options['tenantId']);
        }else{
            $flowerDrives = FlowerDrive::select('FLD_TB.fld_id','fld_name','contact_count', 'FLD_TB.fld_type', DB::raw('DATE(activated_at) as activated_at'),
                DB::raw('DATE(published_at) as published_at'), DB::raw('DATE(start_date) as start_date'),
                DB::raw('DATE(order_by_date) as order_by_date'), 'fld_status',
                DB::raw('DATE(order_by_date) as order_by_date'),
                DB::raw('DATE(cancel_by_date) as cancel_by_date')
                ,'fld_desc','SPECIAL_OCCASSION_TB.occ_name','FLD_TB.org_id')
                ->where('FLD_TB.fld_type', $options['fldType'])
                ->where('FLD_TB.org_id', $user->user_tenant_id);


        }

        $flowerDrives = $flowerDrives
                ->leftJoin('FLD_SITE_TB', 'FLD_TB.fld_id', '=', 'FLD_SITE_TB.fld_id')
                ->leftJoin('SYS_territories', 'FLD_SITE_TB.terr_id', '=', 'SYS_territories.terr_id')
                ->leftJoin('FLD_CONTACT_TB', 'FLD_TB.fld_id', '=', 'FLD_CONTACT_TB.fld_id')
                ->leftJoin('SPECIAL_OCCASSION_TB', 'SPECIAL_OCCASSION_TB.occ_id', '=', 'FLD_TB.special_oc_id')
                ->addSelect(DB::raw("GROUP_CONCAT(distinct SYS_territories.terr_name ORDER BY SYS_territories.terr_name ASC SEPARATOR ', ') terr_name"))
                ->addSelect(DB::raw("GROUP_CONCAT(distinct SYS_territories.terr_id ORDER BY SYS_territories.terr_id ASC SEPARATOR ',') terr_id"))
                ->addSelect(DB::raw("GROUP_CONCAT(distinct FLD_CONTACT_TB.id ORDER BY FLD_CONTACT_TB.id ASC SEPARATOR ',') id"))
                ->groupBy('FLD_TB.fld_id','FLD_SITE_TB.fld_id','SPECIAL_OCCASSION_TB.occ_id');
        if (array_key_exists('sortBy', $options) && !empty($options['sortBy'])) {
            if ($options['sortBy']['column'] == '') {
                $flowerDrives = $flowerDrives->orderByRaw('IFNULL(FLD_TB.updated_at, FLD_TB.created_at) DESC');
            }
            if ($options['sortBy']['column'] == 'fld_name') {
                $flowerDrives = $flowerDrives
                                    ->selectRaw('CAST(REGEXP_SUBSTR(fld_name,"[0-9]+")AS UNSIGNED) AS `concat_srt_fnName`')
                                    ->orderByRaw("REGEXP_SUBSTR(fld_name,'[a-z|A-Z]+') ".$options['sortBy']['type'])
                                    ->orderBy("concat_srt_fnName", $options['sortBy']['type']);
            }
            if ($options['sortBy']['column'] == 'special_occasion') {
                $flowerDrives = $flowerDrives->orderByRaw(orderByWithNullOrEmptyLast('SPECIAL_OCCASSION_TB.occ_name', $options['sortBy']['type']));
            }
            if ($options['sortBy']['column'] == 'description') {
                $flowerDrives = $flowerDrives->orderByRaw(orderByWithNullOrEmptyLast('fld_desc', $options['sortBy']['type']));
            }
            if ($options['sortBy']['column'] == 'start_date') {
                $flowerDrives = $flowerDrives->orderBy('FLD_TB.start_date', $options['sortBy']['type']);
            }
            if ($options['sortBy']['column'] == 'activated_date') {
                $flowerDrives = $flowerDrives->orderBy('activated_at', $options['sortBy']['type']);
            }
            if ($options['sortBy']['column'] == 'emailed_date') {
                $flowerDrives = $flowerDrives->orderBy('published_at', $options['sortBy']['type']);
            }
            if ($options['sortBy']['column'] == 'order_by_date') {
                $flowerDrives = $flowerDrives->orderBy('order_by_date', $options['sortBy']['type']);
            }
            if ($options['sortBy']['column'] == 'cancel_by_date') {
                $flowerDrives = $flowerDrives->orderBy('cancel_by_date', $options['sortBy']['type']);
            }
            if ($options['sortBy']['column'] == 'contacts') {
                $flowerDrives = $flowerDrives->orderBy('order_by_date', $options['sortBy']['type']);
            }
            if ($options['sortBy']['column'] == 'fld_status') {
                $flowerDrives = $flowerDrives->orderByRaw(orderByWithNullOrEmptyLast(DB::raw('CASE WHEN FLD_TB.fld_status = "ACTIVE" THEN "ACTIVE" WHEN  FLD_TB.fld_status = "DRAFT" THEN "DRAFT" WHEN FLD_TB.fld_status = "PUBLISHED" THEN "PUBLISHED" WHEN FLD_TB.fld_status = "COMPLETED" THEN "COMPLETED" END'), $options['sortBy']['type']));
            }
            if ($options['sortBy']['column'] == 'terr_name') {
                $flowerDrives = $flowerDrives->leftJoin('FLD_SITE_TB as FLDST', 'FLD_TB.fld_id', '=', 'FLDST.fld_id')
                    ->leftJoin('SYS_territories as sitesTb', 'FLDST.terr_id', '=', 'sitesTb.terr_id');
//                $flowerDrives = $flowerDrives->addSelect(DB::raw('(SELECT terr_name from SYS_territories WHERE FLD_SITE_TB.terr_id = SYS_territories.terr_id) as terr_name'));
                $flowerDrives = $flowerDrives->addSelect(DB::raw("GROUP_CONCAT(distinct sitesTb.terr_name ORDER BY sitesTb.terr_name ASC SEPARATOR ',') sites"));
                $flowerDrives = $flowerDrives->orderByRaw(orderByWithNullOrEmptyLast('sites', $options['sortBy']['type']));
            }
            if ($options['sortBy']['column'] == 'contacts') {
                $flowerDrives = $flowerDrives->leftJoin('FLD_CONTACT_TB as FLCT', 'FLD_TB.fld_id', '=', 'FLCT.fld_id')
                    ->groupBy('FLD_TB.fld_id', 'FLCT.fld_id')
                    ->addSelect(DB::raw("COUNT(distinct FLCT.fld_id) as count"));
                $flowerDrives = $flowerDrives->orderByRaw(orderByWithNullOrEmptyLast('count', $options['sortBy']['type']));
            }


        }
        else {
            $flowerDrives = $flowerDrives->orderBy('FLD_TB.created_at', 'DESC');
        }

        if (isset($options)) {
            if (isset($options['searchBySite']) && !empty($options['searchBySite'])) {
                $flowerDrives->where('FLD_SITE_TB.terr_id', $options['searchBySite']);
            }
            if (isset($options['advanceSearchFiltersStatus']) && !empty($options['advanceSearchFiltersStatus'])) {
                $flowerDrives->where('FLD_TB.fld_status', $options['advanceSearchFiltersStatus']);
            }
            if (isset($options['advanceSearchFiltersFlowerDrive']) && !empty($options['advanceSearchFiltersFlowerDrive'])) {
                $flowerDrives->where('FLD_TB.fld_name', $options['advanceSearchFiltersFlowerDrive']);
            }
            if (isset($options['advanceSearchFiltersDescription']) && !empty($options['advanceSearchFiltersDescription'])) {
                $flowerDrives->where('FLD_TB.fld_desc', $options['advanceSearchFiltersDescription']);
            }
            // Start Date search, Carbon::parse($startDate)->startOfDay();
            $advanceSearchFiltersStartDateFromDate = isset($options['advanceSearchFiltersStartDateFromDate'])?Carbon::parse($options['advanceSearchFiltersStartDateFromDate'])->startOfDay():null;
            $advanceSearchFiltersStartDateToDate = isset($options['advanceSearchFiltersStartDateToDate'])?Carbon::parse($options['advanceSearchFiltersStartDateToDate'])->endOfDay():null;
            if (isset($options['advanceSearchFiltersStartDateToDate']) && isset($options['advanceSearchFiltersStartDateFromDate']) && !empty($options['advanceSearchFiltersStartDateToDate']) && !empty($options['advanceSearchFiltersStartDateToDate']) ) {
                $flowerDrives->whereBetween('FLD_TB.start_date',[$advanceSearchFiltersStartDateFromDate, $advanceSearchFiltersStartDateToDate] );
            } else if (isset($options['advanceSearchFiltersStartDateFromDate']) && !empty($options['advanceSearchFiltersStartDateFromDate'])
                && isset($options['advanceSearchFiltersStartDateToDate']) && empty($options['advanceSearchFiltersStartDateToDate'])) {
                // if only select 'From Date', return dates between selected date and max date in db
                $endDate = DB::table('FLD_TB')->where('org_id', $user->user_tenant_id)->whereNull('deleted_at')->max('start_date');
                $flowerDrives->whereBetween('FLD_TB.start_date',[$advanceSearchFiltersStartDateFromDate,
                    Carbon::parse($endDate)->endOfDay()] );
            } else if (isset($options['advanceSearchFiltersStartDateToDate']) && !empty($options['advanceSearchFiltersStartDateToDate'])
                && isset($options['advanceSearchFiltersStartDateFromDate']) && empty($options['advanceSearchFiltersStartDateFromDate'])) {
                // if only select 'To Date', return dates between selected date and min date in db
                $startDate = DB::table('FLD_TB')->where('org_id', $user->user_tenant_id)->whereNull('deleted_at')->min('start_date');
                $flowerDrives->whereBetween('FLD_TB.start_date',[ Carbon::parse($startDate)->startOfDay(),
                    $advanceSearchFiltersStartDateFromDate ]);
            }
            // End Start Date search

            // Emailed Date search
            $advanceSearchFilterspublisDateFromDate = isset($options['advanceSearchFilterspublisDateFromDate'])?Carbon::parse($options['advanceSearchFilterspublisDateFromDate'])->startOfDay():null;
            $advanceSearchFilterspublishDateToDate = isset($options['advanceSearchFilterspublishDateToDate'])?Carbon::parse($options['advanceSearchFilterspublishDateToDate'])->endOfDay():null;
            if (isset($options['advanceSearchFilterspublishDateToDate']) && isset($options['advanceSearchFilterspublisDateFromDate']) && !empty($options['advanceSearchFilterspublishDateToDate']) && !empty($options['advanceSearchFilterspublishDateToDate']) ) {
                $flowerDrives->whereBetween('FLD_TB.published_at',[$advanceSearchFilterspublisDateFromDate,
                    $advanceSearchFilterspublishDateToDate] );
            }
            // End Emailed Date search
            else if (isset($options['advanceSearchFilterspublisDateFromDate']) && !empty($options['advanceSearchFilterspublisDateFromDate'])
                && isset($options['advanceSearchFilterspublishDateToDate']) && empty($options['advanceSearchFilterspublishDateToDate'])) {
                // if only select 'From Date', return dates between selected date and max date in db
                $endDate = DB::table('FLD_TB')->where('org_id', $user->user_tenant_id)->whereNull('deleted_at')->max('published_at');
                $flowerDrives->whereBetween('FLD_TB.published_at',[$advanceSearchFilterspublisDateFromDate,
                    Carbon::parse($endDate)->endOfDay()] );
            } else if (isset($options['advanceSearchFilterspublishDateToDate']) && !empty($options['advanceSearchFilterspublishDateToDate'])
                && isset($options['advanceSearchFilterspublisDateFromDate']) && empty($options['advanceSearchFilterspublisDateFromDate'])) {
                // if only select 'To Date', return dates between selected date and min date in db
                $startDate = DB::table('FLD_TB')->where('org_id', $user->user_tenant_id)->whereNull('deleted_at')->min('published_at');
                $flowerDrives->whereBetween('FLD_TB.published_at',[Carbon::parse($startDate)->startOfDay(),
                    $advanceSearchFilterspublisDateFromDate]);
            }
        }
        if($options['filterForCopying'] == true) {
            $flowerDrives = $flowerDrives->whereIn('fld_status', [FLD_COMPLETED, FLD_PUBLISHED,FLD_ACTIVE]);
        }
        $flowerDrives = $this->_searchChunks($flowerDrives, $options);
        if ($pluck != '') {
            $flowerDrives = $flowerDrives->pluck($pluck);
        } elseif (!empty($options['paginate'])) {
            if ($options['isPaginate'] == false) {
                $flowerDrives = $flowerDrives->get();
            } else {
                $flowerDrives = $flowerDrives->paginate($options['paginate']);
            }
        } else {
            $flowerDrives = $flowerDrives->get();
        }

        $flowerDiveSites = [];
        $selectedSites =[];
        $totalCount =0;
        foreach ($flowerDrives as $key=>$value){

            $fldId = $value['fld_id'];

            //get Orders count
            $flowerDriveOdersList = ConsumerOrder::where('fld_id',$value['fld_id'])->get();
            $countOrders = count($flowerDriveOdersList->toArray());
            $totalCount = $totalCount + $countOrders;
            $value['orderCount']  = $countOrders;

            //This is a tempory solution for advanced search site wise
            if((isset($options['searchBySite']) && !empty($options['searchBySite'])) || (isset($options['search']) && !empty($options['search']))){
                $flowerDiveSites = DB::table('SYS_territories')->select('FLD_SITE_TB.fld_id',DB::raw("GROUP_CONCAT(distinct SYS_territories.terr_name SEPARATOR ', ') terr_name"))
                                    ->leftJoin('FLD_SITE_TB', 'SYS_territories.terr_id', '=', 'FLD_SITE_TB.terr_id')
                                    ->where('FLD_SITE_TB.fld_id',$fldId)
                                    ->groupBy('FLD_SITE_TB.fld_id')
                                    ->first();
                $value['terr_name'] = $flowerDiveSites->terr_name;
            }
            $countLocation = DB::table('CONSUMER_ORDERS_TB')->select('CONSUMER_ORDER_ITEM_LOC_TB.location_id')
                ->leftJoin('CONSUMER_ORDER_ITEMS_TB', 'CONSUMER_ORDER_ITEMS_TB.consumer_order_id', '=', 'CONSUMER_ORDERS_TB.consumer_order_id')
                ->leftJoin('CONSUMER_ORDER_ITEM_LOC_TB', 'CONSUMER_ORDER_ITEM_LOC_TB.consumer_order_item_id', '=', 'CONSUMER_ORDER_ITEMS_TB.consumer_order_item_id')
                ->where('CONSUMER_ORDER_ITEMS_TB.is_pkg',0)
                ->where('CONSUMER_ORDERS_TB.fld_id', $fldId)
                ->get()->toArray();

            $uniqueLocations = [];
            if (!empty($countLocation)) {
                foreach ($countLocation as $location) {
                    if (!array_key_exists($location->location_id, $uniqueLocations)) {
                        $uniqueLocations[$location->location_id] =  $location->location_id;
                    }
                }
            }

            $totalCount = $totalCount + count($uniqueLocations);
            $value['locationCount']  = count($uniqueLocations);
        }

        return $flowerDrives;
    }
    private function _searchChunks($flowerDrives, $options)
    {
        $user = getLoggedInUser();
        $searchOptions = wildCardCharacterReplaced($options['search']);
        if (array_key_exists('columns', $options) && $options['columns'] != null) {
            $selectedColumn = array_flip($options['columns']);
        }
        if (array_key_exists('search', $options)) {
            $options['search'] = wildCardCharacterReplace($options['search']);
            $search = $options['search'];
            if($options['filterForCopying'] == true){
                if ($options['search'] != "" && isset($selectedColumn['flower_drive'])) {
                    $flowerDrives = $flowerDrives->where('FLD_TB.fld_name', 'like', '%' . $searchOptions . '%')->whereIn('fld_status', [FLD_COMPLETED, FLD_PUBLISHED,FLD_ACTIVE])->where('fld_type', $options['fldType']);;
                } else if ($options['search']) {
                    $flowerDrives = $flowerDrives->whereRaw('0 != 0');
                }
                if ($options['search'] != "" && isset($selectedColumn['start_date'])) {
                    $flowerDrives = $flowerDrives->orWhereRaw("((select DATE_FORMAT(CCV.start_date , '%d-%M-%Y') as start_date  from FLD_TB as CCV where CCV.fld_id = FLD_TB.fld_id  )) like '%" . $search . "%'")->whereIn('fld_status', [FLD_COMPLETED, FLD_PUBLISHED,FLD_ACTIVE])->where('fld_type', $options['fldType']);;
                    $flowerDrives = $this->_getLoggedUserData($flowerDrives, $options);
                }
                if ($options['search'] != "" && isset($selectedColumn['cancel_by_date'])) {
                    $flowerDrives = $flowerDrives->orWhereRaw("((select DATE_FORMAT(CCV.cancel_by_date , '%d-%M-%Y') as cancel_by_date  from FLD_TB as CCV where CCV.fld_id = FLD_TB.fld_id  )) like '%" . $search . "%'")->whereIn('fld_status', [FLD_COMPLETED, FLD_PUBLISHED,FLD_ACTIVE])->where('fld_type', $options['fldType']);;
                    $flowerDrives = $this->_getLoggedUserData($flowerDrives, $options);
                }
                if ($options['search'] != "" && isset($selectedColumn['status'])) {
                    $flowerDrives = $flowerDrives->orWhere('FLD_TB.fld_status', 'like', '%' . $options['search'] . '%')->whereIn('fld_status', [FLD_COMPLETED, FLD_PUBLISHED,FLD_ACTIVE])->where('fld_type', $options['fldType']);;
                    $flowerDrives = $this->_getLoggedUserData($flowerDrives, $options);
                }
                if ($options['search'] != "" && isset($selectedColumn['fld_type'])) {
                    $flowerDrives = $flowerDrives->orWhere('FLD_TB.fld_type', 'like', '%' . $options['search'] . '%')->whereIn('fld_type', [FLD_TYPE_CAMPAIGN, FLD_TYPE_STORE])->where('fld_type', $options['fldType']);;
                    $flowerDrives = $this->_getLoggedUserData($flowerDrives, $options);
                }
                if ($options['search'] != "" && isset($selectedColumn['order_by_date'])) {
                    $flowerDrives = $flowerDrives->orWhereRaw("((select DATE_FORMAT(CCV.order_by_date , '%d-%M-%Y') as order_by_date  from FLD_TB as CCV where CCV.fld_id = FLD_TB.fld_id  )) like '%" . $search . "%'")->whereIn('fld_status', [FLD_COMPLETED, FLD_PUBLISHED,FLD_ACTIVE])->where('fld_type', $options['fldType']);;
                    $flowerDrives = $this->_getLoggedUserData($flowerDrives, $options);
                }
                if ($options['search'] != "" && isset($selectedColumn['emailed_date'])) {
                    $flowerDrives = $flowerDrives->orWhereRaw("((select DATE_FORMAT(CCV.published_at , '%d-%M-%Y') as published_at  from FLD_TB as CCV where CCV.fld_id = FLD_TB.fld_id  )) like '%" . $search . "%'")->whereIn('fld_status', [FLD_COMPLETED, FLD_PUBLISHED,FLD_ACTIVE])->where('fld_type', $options['fldType']);;
                    $flowerDrives = $this->_getLoggedUserData($flowerDrives, $options);
                }
                if ($options['search'] != "" && isset($selectedColumn['description'])) {
                    $flowerDrives = $flowerDrives->orWhere('FLD_TB.fld_desc', 'like', '%' . $searchOptions . '%')->whereIn('fld_status', [FLD_COMPLETED, FLD_PUBLISHED,FLD_ACTIVE])->where('fld_type', $options['fldType']);;
                    $flowerDrives = $this->_getLoggedUserData($flowerDrives, $options);
                }
                if ($options['search'] != "" && isset($selectedColumn['special_occasion'])) {
                    $searchQuery = str_replace("''", "\'", $options['search']);
                    $flowerDrives = $flowerDrives->orWhere('SPECIAL_OCCASSION_TB.occ_name', 'like', '%' . $searchQuery . '%')->whereIn('fld_status', [FLD_COMPLETED, FLD_PUBLISHED,FLD_ACTIVE])->where('fld_type', $options['fldType']);;
                    $flowerDrives = $this->_getLoggedUserData($flowerDrives, $options);
                }
                if ($options['search'] != "" && isset($selectedColumn['activated_at'])) {
                    $flowerDrives = $flowerDrives->orWhereRaw("((select DATE_FORMAT(CCV.activated_at , '%d-%M-%Y') as activated_at  from FLD_TB as CCV where CCV.fld_id = FLD_TB.fld_id  )) like '%" . $search . "%'")->whereIn('fld_status', [FLD_COMPLETED, FLD_PUBLISHED,FLD_ACTIVE])->where('fld_type', $options['fldType']);;
                    $flowerDrives = $this->_getLoggedUserData($flowerDrives, $options);
                }
                if ($options['search'] != "" && isset($selectedColumn['site'])) {
                    $search = htmlentities($searchOptions); // site saved with htmlSpecial characters, convert the string with htmlSpecial characters
                    $search = str_replace("'", '&apos;', $search);
                    $flowerDrives = $flowerDrives->leftJoin('FLD_SITE_TB as FLDT', 'FLD_TB.fld_id', '=', 'FLDT.fld_id')
                        ->leftJoin('SYS_territories as siteTb', 'FLDT.terr_id', '=', 'siteTb.terr_id');
                    $flowerDrives = $flowerDrives->addSelect(DB::raw("GROUP_CONCAT(distinct siteTb.terr_name ORDER BY siteTb.terr_name ASC SEPARATOR ',') sites"));
                    $flowerDrives = $flowerDrives->Orwhere('siteTb.terr_name', 'like', '%' . "$search" . '%')->where('fld_type', $options['fldType'])
                        ->where('FLD_TB.org_id', $user->user_tenant_id);
                    $flowerDrives = $this->_getLoggedUserData($flowerDrives, $options);
                }
                if ($options['search'] != "" && isset($selectedColumn['published_at'])) {
                    $flowerDrives = $flowerDrives->orWhereRaw("((select DATE_FORMAT(CCV.published_at , '%d-%M-%Y') as published_at  from FLD_TB as CCV where CCV.fld_id = FLD_TB.fld_id  )) like '%" . $search . "%'")->whereIn('fld_status', [FLD_COMPLETED, FLD_PUBLISHED,FLD_ACTIVE])->where('fld_type', $options['fldType']);;
                    $flowerDrives = $this->_getLoggedUserData($flowerDrives, $options);
                }
            }else {
                $selectedColumn= isset($selectedColumn) ? $selectedColumn : null;
                $flowerDrives->where(function($query)use($options,$searchOptions,$user,$selectedColumn,$search){               
                if ($options['search'] != "" && isset($selectedColumn['flower_drive'])) {
                    $query = $query->where('FLD_TB.fld_name', 'like', '%' . $searchOptions . '%')->where('fld_type', $options['fldType']);;
                } else if ($options['search']) {
                    $query = $query->whereRaw('0 != 0');
                }
                if ($options['search'] != "" && isset($selectedColumn['start_date'])) {
                    $query = $query->orWhereRaw("((select DATE_FORMAT(CCV.start_date , '%d-%M-%Y') as start_date  from FLD_TB as CCV where CCV.fld_id = FLD_TB.fld_id  )) like '%" . $search . "%'")->where('fld_type', $options['fldType']);;
                    $query = $this->_getLoggedUserData($query, $options);
                }
                if ($options['search'] != "" && isset($selectedColumn['cancel_by_date'])) {
                    $query = $query->orWhereRaw("((select DATE_FORMAT(CCV.cancel_by_date , '%d-%M-%Y') as cancel_by_date  from FLD_TB as CCV where CCV.fld_id = FLD_TB.fld_id  )) like '%" . $search . "%'")->where('fld_type', $options['fldType']);;
                    $query = $this->_getLoggedUserData($query, $options);
                }
                if ($options['search'] != "" && isset($selectedColumn['status'])) {
                    $query = $query->orWhere('FLD_TB.fld_status', 'like', '%' . $options['search'] . '%')->where('fld_type', $options['fldType']);;
                    $query = $this->_getLoggedUserData($query, $options);
                }
                if ($options['search'] != "" && isset($selectedColumn['fld_type'])) {
                    $query = $query->orWhere('FLD_TB.fld_type', 'like', '%' . $options['search'] . '%')->where('fld_type', $options['fldType']);;
                    $query = $this->_getLoggedUserData($query, $options);
                }
                if ($options['search'] != "" && isset($selectedColumn['order_by_date'])) {
                    $query = $query->orWhereRaw("((select DATE_FORMAT(CCV.order_by_date , '%d-%M-%Y') as order_by_date  from FLD_TB as CCV where CCV.fld_id = FLD_TB.fld_id  )) like '%" . $search . "%'")->where('fld_type', $options['fldType']);;
                    $query = $this->_getLoggedUserData($query, $options);
                }
                if ($options['search'] != "" && isset($selectedColumn['emailed_date'])) {
                    $query = $query->orWhereRaw("((select DATE_FORMAT(CCV.published_at , '%d-%M-%Y') as published_at  from FLD_TB as CCV where CCV.fld_id = FLD_TB.fld_id  )) like '%" . $search . "%'")->where('fld_type', $options['fldType']);;
                    $query = $this->_getLoggedUserData($query, $options);
                }
                if ($options['search'] != "" && isset($selectedColumn['description'])) {
                    $query = $query->orWhere('FLD_TB.fld_desc', 'like', '%' . $searchOptions . '%')->where('fld_type', $options['fldType']);;
                    $query = $this->_getLoggedUserData($query, $options);
                }
                if ($options['search'] != "" && isset($selectedColumn['special_occasion'])) {
                    $searchQuery = str_replace("''", "\'", $options['search']);
                    $query = $query->orWhere('SPECIAL_OCCASSION_TB.occ_name', 'like', '%' . $searchQuery . '%')->where('fld_type', $options['fldType']);;
                    $query = $this->_getLoggedUserData($query, $options);
                }
                if ($options['search'] != "" && isset($selectedColumn['activated_at'])) {
                    $query = $query->orWhereRaw("((select DATE_FORMAT(CCV.activated_at , '%d-%M-%Y') as activated_at  from FLD_TB as CCV where CCV.fld_id = FLD_TB.fld_id  )) like '%" . $search . "%'")->where('fld_type', $options['fldType']);;
                    $query = $this->_getLoggedUserData($query, $options);
                }
                if ($options['search'] != "" && isset($selectedColumn['site'])) {
                    $search = htmlentities($searchOptions); // site saved with htmlSpecial characters, convert the string with htmlSpecial characters
                    $search = str_replace("'", '&apos;', $search);
                    $query = $query->leftJoin('FLD_SITE_TB as FLDT', 'FLD_TB.fld_id', '=', 'FLDT.fld_id')
                        ->leftJoin('SYS_territories', 'FLDT.terr_id', '=', 'SYS_territories.terr_id');
                    $query = $query->addSelect(DB::raw("GROUP_CONCAT(distinct SYS_territories.terr_name ORDER BY SYS_territories.terr_name ASC SEPARATOR ',') sites"));
                    $query = $query->Orwhere('SYS_territories.terr_name', 'like', '%' . "$search" . '%')
                        ->where('FLD_TB.org_id', $user->user_tenant_id)->where('fld_type', $options['fldType']);;
                    $query = $this->_getLoggedUserData($query, $options);
                }
                if ($options['search'] != "" && isset($selectedColumn['published_at'])) {
                    $query = $query->orWhereRaw("((select DATE_FORMAT(CCV.published_at , '%d-%M-%Y') as published_at  from FLD_TB as CCV where CCV.fld_id = FLD_TB.fld_id  )) like '%" . $search . "%'")->where('fld_type', $options['fldType']);;
                    $query = $this->_getLoggedUserData($query, $options);
                }
            });
            }
        }
        return $flowerDrives;
    }
    private function _getLoggedUserData($flowerDrives, $options = [], $tenantId = null)
    {
        $user = getLoggedInUser();
        $relationship = $tenantId ? 'getTerritoryPermissionFromJob' : 'territoryPermission';
        if (empty($user) && array_key_exists('user_id', $options)) {
            $user = User::find($options['user_id']);
        }

        return  $flowerDrives->whereHas('flowerDriveSite', function ($q) use ($user, $relationship,$flowerDrives) {
            $q->whereHas('territory', function ($q) use ($user, $relationship,$flowerDrives) {
                $q->where('terr_tenant_id', '=', $user->user_tenant_id);
                if (!$user->is_account_owner) {
                    $q->WhereHas($relationship, function ($query) use ($user) {
                        $query->where('user_id', $user->id);
                        $query->where('read', true);
                    });
                }
            });
        });

    }

    /**
     * Delete Term and conition
     *
     * @param TermAndCondition $term
     *
     * @return array
     */
    public function deleteFlowerDrive($flowerDrive)
    {
        FlowerDrive::find($flowerDrive)->delete();
        $response['message'] = 'Floral Program delete successfully';
        return $response;

    }
    public function getInvoiceDetails($invoiceId)
    {
        $invoice = ConsumerInvoice::where('invoice_number', $invoiceId)
            ->select('invoice_number', 'consumer_order_tb_id', 'status', 'inv_date', 'inv_total', 'sub_total',
                'tax_total', 'currency', 'org_id', 'person_id')
            ->with(['order' => function ($q) {
                $q->select('invoice_number', 'order_number');
            }])
            ->with(['person' => function ($person) {
                $person->select('first_name', 'last_name', 'id')
                    ->with(['primaryAddress' => function ($address) {
                        $address->select('address1', 'address2', 'region_id', 'person_id', 'region_id')
                            ->with(['region' => function ($region) {
                                $region->select('country_id', 'town_short_name', 'town_long_name', 'id', 'postal_id')
                                    ->with(['country' => function ($country) {
                                        $country->select('id', 'country_name');
                                    }])
                                    ->with(['postalCode' => function ($postal) {
                                        $postal->select('postal_code', 'id');
                                    }]);

                            }]);
                    }]);
            }])
            ->with(['order' => function ($order) {
                $order->select('consumer_order_id', 'invoice_number', 'order_number')
                    ->with(['payment' => function ($payment) {
                        $payment->select('consumer_payment_id', 'payment_receipt_id', 'consumer_order_id')
                            ->with(['paymentReceipt' => function ($receipt) {
                                $receipt->select('currency', 'amount', 'customer_name', 'pay_date', 'pay_type', 'pay_rcpt_id', 'pm_id','customer_email')
                                    ->with(['paymentMethod' => function ($method) {
                                        $method->select('pay_mth_id', 'pay_mth_name');
                                    }]);
                            }]);
                    }])->with(['orderItems' => function ($q) {
                        $q->select('quantity', 'item_price', 'item_id', 'consumer_order_id')
                            ->with(['item' => function ($query) {
                                $query->select('item_code', 'item_name', 'item_cat_id', 'item_id');
                            }]);
                    }]);
            }])
            ->first();

        $territory = Territory::where('terr_tenant_id', $invoice->org_id)->first();

        if ($territory) {
            if ($territory->tenant) {
                $invoice->site_name = $territory->terr_name;
                $invoice->site_address1 = $territory->address_1;
                $invoice->site_address2 = $territory->address_2;
                $invoice->tax_number = $territory->tax_number;
                $invoice->country = $territory->territoryCountry->country_name;
                $invoice->suburb = $territory->region->town_short_name;
                $invoice->town = $territory->region->town_long_name;
                $invoice->postal_code = $territory->region->postalCode->postal_code;
                $invoice->tax_number = $territory->tax_number;
                $invoice->website_url = $territory->website_url;
                $invoice->contact_phone = $territory->contact_phone;
                $invoice->contact_email = $territory->contact_email;
            }
        }

        return $invoice;
    }

    public function getConsumerState($stateId)
    {
        $data = CountryStates::where('id', $stateId)
            ->select('id', 'country_id', 'state_name')
            ->with(['country' => function ($q) {
                $q->select('country_name', 'id');
            }])
            ->first();

        return $data;
    }

    public function getReceiptDetails($receiptId)
    {
        $payment = ConsumerPayment::where('payment_receipt_id', $receiptId)
            ->select('consumer_payment_id', 'consumer_order_id', 'payment_receipt_id')
            ->with(['paymentReceipt' => function ($receipt) {
                $receipt->select('pay_rcpt_id', 'currency', 'amount', 'customer_name', 'pay_date', 'pm_id', 'rcpt_no','customer_email')
                    ->with(['paymentMethod' => function ($method) {
                        $method->select('pay_mth_id', 'pay_mth_name');
                    }]);
            }])
            ->with(['order' => function ($order) {
                $order->select('consumer_order_id', 'order_number', 'consumer_id', 'org_id')
                    ->with(['consumer' => function ($consumer) {
                        $consumer->select('consumer_id', 'person_id')
                            ->with(['person' => function ($person) {
                                $person->select('first_name', 'last_name', 'id')
                                    ->with(['primaryAddress' => function ($address) {
                                        $address->select('address1', 'address2', 'region_id', 'person_id', 'region_id')
                                            ->with(['region' => function ($region) {
                                                $region->select('country_id', 'town_short_name', 'town_long_name', 'id', 'postal_id')
                                                    ->with(['country' => function ($country) {
                                                        $country->select('id', 'country_name');
                                                    }])
                                                    ->with(['postalCode' => function ($postal) {
                                                        $postal->select('postal_code', 'id');
                                                    }]);

                                            }]);
                                    }]);
                            }]);
                    }]);
            }])
            ->first();

        $territory = Territory::where('terr_tenant_id', $payment->order->org_id)->first();

        if ($territory) {
            if ($territory->tenant) {
                $payment->site_name = $territory->terr_name;
                $payment->site_address1 = $territory->address_1;
                $payment->site_address2 = $territory->address_2;
                $payment->tax_number = $territory->tax_number;
                $payment->country = $territory->territoryCountry->country_name;
                $payment->suburb = $territory->region->town_short_name;
                $payment->town = $territory->region->town_long_name;
                $payment->postal_code = $territory->region->postalCode->postal_code;
                $payment->tax_number = $territory->tax_number;
                $payment->website_url = $territory->website_url;
                $payment->contact_phone = $territory->contact_phone;
                $payment->contact_email = $territory->contact_email;
            }

        }

        return $payment;
    }

    /**
     * Get Flower Drive By Id For Interaction
     * @param $fld_id
     * @param $contact_id
     * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Model|mixed|object|null
     */
    public function getFlowerDriveByIdForInteraction($fld_id, $contact_id)
    {
        return FlowerDrive::query()
            ->select('FLD_TB.fld_id', 'FLD_TB.fld_name', 'FLD_TB.fld_desc', 'FLD_TB.fld_status', 'FLD_TB.cancel_by_date', 'FLD_TB.complete_by_date',
                'FLD_TB.order_by_date', 'FLD_TB.org_id', 'FLD_TB.supply_by_date', 'FLD_TB.start_date', 'FLD_CONTACT_TB.id AS fld_contact_tb_id', 'FLD_CONTACT_TB.contact_id')
            ->join('FLD_CONTACT_TB', function ($join) use ($fld_id, $contact_id) {
                $join->on('FLD_TB.fld_id', '=', 'FLD_CONTACT_TB.fld_id')
                    ->where('FLD_CONTACT_TB.contact_id', '=', $contact_id);
            })
            ->where('FLD_TB.fld_id', $fld_id)
            ->first();
    }


    public function getAllFldPaperTemplateChunkList($contactList)
    {
        $chunkList = array();
        $chunkList['data'] = array();
        $chunk = 5;
        $per_page = 10;
        $con_list_count = count($contactList);

        for ($x = 1; $x <= $per_page; $x++) {

            $chunk_start = 0;
            $chunk_end = 0;

            $remaining_contacts = 0;
            $remaining_contacts_for_selected_chunk = $con_list_count - (($x) * $chunk);
            if($con_list_count > $chunk){
                $chunk_end = ($x)*$chunk;
            }else{
                $chunk_end = $con_list_count;
            }

            if($x == 1){
                $chunk_start = $x;
            }
            else{
                $chunk_start = (($x-1)*$chunk)+1;

            }

            $chunkList['data'][$x]['fld_po_id'] = $x;
            $chunkList['data'][$x]['is_printed'] = 1;
            $chunkList['data'][$x]['chunk_start'] = $chunk_start;
            $chunkList['data'][$x]['chunk_end'] = $chunk_end;

            if($remaining_contacts_for_selected_chunk < 1) {
                break;
            }
        }
        $chunkList['total'] = count($contactList);
        $chunkList['per_page'] = $per_page;
        $chunkList['current_page'] = 1;
        $chunkList['last_page'] = 3;
        $chunkList['from'] = 0;
        $chunkList['to'] = 10;
        $chunkList['next_page_url'] = '';
        $chunkList['prev_page_url'] = '';
        $chunkList['api_resource'] = true;

        return $chunkList;
    }

    private function _searchChunksRawSql($pOList, $options, $userTenantId)
    {
        if ($options['columns'] != null) {
            $selectedColumn = array_flip($options['columns']);
        }
        $options['search'] = wildCardCharacterReplace($options['search']);

        if ($options['search'] != "" && !empty($selectedColumn)) {
            $pOList = $pOList->where(function ($query) use ($options, $selectedColumn) {
                if ($options['search'] != "" && (isset($selectedColumn['fld_po_no']))) {
                    $query->orWhere('fld_po_no', 'like', '%' . $options['search'] . '%');
                }
                if ($options['search'] != "" && (isset($selectedColumn['to_org_id']))) {
                    $query->orWhere('SYS_tenants.tena_name', 'like', '%' . $options['search'] . '%');
                }
                if ($options['search'] != "" && (isset($selectedColumn['from_org_id']))) {
                    $query->orWhere('SYS_territories.terr_name', 'like', '%' . $options['search'] . '%');
                }
                if ($options['search'] != "" && (isset($selectedColumn['fld_id']))) {
                    $query->orWhere('FLD_TB.fld_name', 'like', '%' . $options['search'] . '%');
                }
                if ($options['search'] != "" && (isset($selectedColumn['order_total']))) {
                    $query->orWhere('order_total', 'like', '%' . $options['search'] . '%');
                }
                if ($options['search'] != "" && isset($selectedColumn['order_date'])) {
                    $query->orWhere('order_date', 'like', '%' . $options['search'] . '%');
                }
                if ($options['search'] != "" && isset($selectedColumn['sup_by_date'])) {
                    $query->orWhere('sup_by_date', 'like', '%' . $options['search'] . '%');
                }
                if ($options['search'] != "" && isset($selectedColumn['po_status'])) {
                    if ($options['search'] == "not_sent") {
                        $options['search'] = 'NOT_SENT';
                    } else if ($options['search'] == "ordered") {
                        $options['search'] = 'ORDERED';
                    } else if ($options['search'] == "cancelled") {
                        $options['search'] = 'CANCELLED';
                    } else if ($options['search'] == "received") {
                        $options['search'] = 'RECEIVED';
                    }
                    $query->orWhere('po_status', 'like', '%' . $options['search'] . '%');
                }
            })->where('from_org_id', $userTenantId);
        }

        if (isset($options)) {
            if (isset($options['searchByPurchaseNumber']) && !empty($options['searchByPurchaseNumber'])) {
                $pOList->where('fld_po_no', 'Like', '%' . $options['searchByPurchaseNumber'] . '%');
            }
            if (!empty($options['searchByPoSite'])) {
               $pOList->whereIn('SYS_territories.terr_id', $options['searchByPoSite']);
            }

            if(!empty($options['searchByPoSupplier'])){
                $pOList->where('to_org_id', '=', $options['searchByPoSupplier']);
            }
            if (isset($options['searchByPoFlowerDrive']) && !empty($options['searchByPoFlowerDrive'])) {
                $pOList->whereHas('flowerDrive', function ($q) use ($options) {
                    $q->where('FLD_TB.fld_name', 'like', '%' .
                        $options['searchByPoFlowerDrive'] . '%');
                });
            }
            if (isset($options['searchByPoOrderDate']) && !empty($options['searchByPoOrderDate'])) {
                $pOList->where('order_date', 'Like', '%' . date("Y-m-d", strtotime($options['searchByPoOrderDate'])) . '%');
            }
            if (isset($options['searchByPoCompletedDate']) && !empty($options['searchByPoCompletedDate'])) {
                $pOList->where('sup_by_date', 'Like', '%' . date("Y-m-d", strtotime($options['searchByPoCompletedDate'])) . '%');
            }
            if (isset($options['searchByPoStaus']) && $options['searchByPoStaus'] != '') {
                $pOList->where('po_status', 'Like', '%' . $options['searchByPoStaus'] . '%');

            }

        }

        return $pOList;
    }

    public function getFlowerDriveContactList($fldId, $start = '', $end = '', $list_no = 1)
    {
        $chunk_size = 0;
        if ($start != '' && $end != '') {
            $chunk_size = ((int)$end + 1) - (int)$start;
        }
        $contactList = FlowerDriveByondMarketContact::select('id','fld_id','terr_id','contact_email','email_template_id','email_sent_count', 'fld_contact_id')->with(['Territory'=> function($q){
            $q->select('terr_name','terr_id','contact_email');
        }, 'fldContacts' => function($q) {
            $q->select('id', 'contact_id')->with('person.relationships.deceasedRelatedPerson');
        }])
            ->where('fld_id', '=', $fldId);
        if ($chunk_size > 0) {
            $contactList = $contactList->paginate($chunk_size, ['*'], 'page', $list_no);
        } else {
            $contactList = $contactList->paginate($contactList->count());
        }
        return $contactList;
    }

    public function getConsumerOrderList($fld_id)
    {
        $orders = ConsumerOrder::select('consumer_order_id', 'grand_total', 'consumer_order_id', 'fld_id', 'terr_id', 'grand_total', 'billing_info', 'order_number')
            ->where('fld_id', $fld_id)
            ->with(['orderItems' => function ($q) {
                $q->select('item_id', 'consumer_order_item_id', 'consumer_order_id', 'quantity', 'is_del_placement', 'is_pkg')
                    ->where('is_pkg', 0)
                    ->with(['item' => function ($q) {
                        $q->select('item_id', 'item_name', 'supplier_item_code');
                },'orderItemLocDetails' => function ($q) {
                    $q->select('consumer_order_item_id', 'location_id', 'decease_person_id' )->with(['person' => function($q){
                        $q->select('id', 'first_name', 'last_name');
                    }, 'location' => function($q){
                        $q->select('id', 'name', 'code');
                    }]);
                }]);
            },
                //get site details for each consumer order
                'territories' => function ($q) {
                    $q->select('terr_id', 'terr_name', 'address_1', 'address_2', 'country', 'tax_number', 'region_id', 'terr_tenant_id', 'comp_id', 'site_id', 'contact_phone')->with(['territoryCountry' => function ($q) {
                        $q->select('id', 'country_name');
                    },
                        //get site state,country
                        'region' => function ($q) {
                            $q->select('id', 'postal_id', 'country_state_id', 'town_short_name')->with(['postalCode' => function ($q) {
                                $q->select('id', 'postal_code');
                            }, 'countryState' => function ($q) {
                                $q->select('id', 'state_name');
                            }]);
                        },
                        //get tenants country name , postal code  and state name
                        'tenant' => function ($q) {
                            $q->select('tena_id', 'registration_id', 'tena_type')->with(['registration' => function ($qeee) {
                                $qeee->select('regi_id', 'regi_address_1', 'regi_address_2', 'regi_region_id', 'regi_org_name')->with(['region' => function ($q) {
                                    $q->select('id', 'country_id', 'country_state_id', 'postal_id', 'town_short_name')->with(['country' => function ($q) {
                                        $q->select('id', 'country_name');
                                    }, 'countryState' => function ($q) {
                                        $q->select('id', 'state_name');
                                    }, 'postalCode' => function ($q) {
                                        $q->select('id', 'postal_code');
                                    }
                                    ]);
                                }]);
                            }, 'parentTerritory' => function ($q) {
                                $q->select('terr_tenant_id', 'contact_email');
                            }]);
                        }]);
                }
            , 'flowerDrive' => function ($q) {
                    $q->select('fld_id', 'supply_by_date', 'complete_by_date');
                }])
            ->whereHas('orderItems', function ($query) {
                $query->where('is_pkg', 0)
                    ->whereNotNull('fld_po_id')
                    ->whereNotNull('fld_po_line_id');
            })->get();
        return $orders;
    }
    public function getFlowerDriveListForConsumerOrders($options = [])
    {
        $loginUser= getLoggedInUser()->user_tenant_id;

        $fld_list = FlowerDrive::select('fld_id', 'fld_name')
            ->where('fld_status','PUBLISHED')
            ->whereHas('consumerOrder.orderItems', function ($query) use ($loginUser){
                $query->where('is_pkg', 0)->where('item_supp_id',$loginUser)
                    ->whereNotNull('fld_po_id')
                    ->whereNotNull('fld_po_line_id');
            });
        $fld_list = $this->_searchChunksRawSqlForFlowerDriveListForConsumerOrders($fld_list, $options);
        if (array_key_exists('sortBy', $options) && !empty($options['sortBy'])) {
            if ($options['sortBy']['column'] == 'fld_name') {
                $fld_list = $fld_list->orderBy('fld_name', $options['sortBy']['order']);
            }
        }

        if (!empty($options['paginate'])) {
            $fld_list = $fld_list->paginate($options['paginate']);
        } else {
            $fld_list = $fld_list->paginate($fld_list->count());
        }

        return $fld_list;

    }

    private function _searchChunksRawSqlForFlowerDriveListForConsumerOrders($lists, $options)
    {
        if ($options['columns'] != null) {
            $selectedColumn = array_flip($options['columns']);
        }
        $options['search'] = wildCardCharacterReplace($options['search']);

        if ($options['search'] != "" && (isset($selectedColumn['fld_name']))) {
            $lists->where('fld_name', 'like', '%' . $options['search'] . '%');
        }

        return $lists;
    }

    public function getContactListForFlowerDriveLaunch($request,$siteIds=null, $user=null,$tenantId=null,$isAccountOwner=null,$fldDriveId=null,$isFldResend=null,$contactList){
        $requestParams = $request;
        $contactIdsListForEmails = [];

        $fldId =  $fldDriveId;
        // for FLD contacts details
        $fldRequiredDetails =    FlowerDrive::query()->where('fld_id', $fldId)->select( 'org_id','contact_list_id')->first();
        $orgId = $fldRequiredDetails->org_id;

        $contactListId = isset($fldId)?$fldRequiredDetails->contact_list_id: $request['contactId'];
        $bmOrgIdDetails =[];
        $siteIdList =[];
        $option = $this->_prepareContactListDataArray($requestParams,$isFldResend);
//        $contactList = isset($contactList)?$contactList: $this->contactListRepo->getContactListForPreview($option, $contactListId,'',null, $user,$tenantId,$isAccountOwner);
        //get BM details
        $bmOrgDetails = $this->organizationSettingsRepo->getOrgSettingsData($orgId,$siteIds);
        $bmOrgDetailId = $bmOrgDetails &&  isset($bmOrgDetails[0]->org_detail_id)?$bmOrgDetails[0]->org_detail_id:'';
        array_push($bmOrgIdDetails,$bmOrgDetailId);
        $bmDetailsType = $this->getBmDetailType($bmOrgDetailId);

        // add details of BM and FLD contacts again
        if ($contactList != null && isset($contactList)) {
            // $contactList = new ContactListResourcesCollection($contactList);
            foreach ($contactList as $contact){
                $siteIdList = explode(',', $contact->terr_ids);
                //select site ids from decease
                // if($contact->relationships){
                //     $relationships =  $contact->relationships;
                //     foreach ($relationships as $relationship){
                //         if($relationship->deceasedRelatedPerson &&
                //             $relationship->deceasedRelatedPerson->deceasedPersonLocation &&
                //             $relationship->deceasedRelatedPerson->deceasedPersonLocation->site &&
                //             $siteIds && in_array($relationship->deceasedRelatedPerson->deceasedPersonLocation->site->terr_id,$siteIds)){
                //             array_push($siteIdList,$relationship->deceasedRelatedPerson->deceasedPersonLocation->site->terr_id);
                //         }
                //     }
                // }
                // if ContactList has record with Right Holder
                // if ($contact->rightsHolders) {
                //     $rightHolderDeceased = $contact->rightsHolders;
                //     foreach ($rightHolderDeceased as $rightHolder) {
                //         if ($rightHolder->deceasedLocation) {
                //             foreach ($rightHolder->deceasedLocation as $deceased) {
                //                 if ($deceased->site && $deceased->site->terr_id) {
                //                     array_push($siteIdList, $deceased->site->terr_id);
                //                 }
                //             }
                //         }
                //     }
                // }
                // remove duplicates from $siteIdList
                // $siteIdList = array_unique($siteIdList);
                $preparedContactListData = $this->_extractAndReturnNewContactDetailsPublishJob($contact,$fldId,$user);
                $fldContact= FlowerDriveContact::create($preparedContactListData);
                foreach ($siteIdList as $siteId){
                    if($bmOrgDetailId){
                        FlowerDriveSite::where('fld_id', $fldId)->where('terr_id', $siteId)->update( ['bm_org_detail_id' =>$bmOrgDetailId]);
                    }
                    $preparedContactListSiteData = $this->_extractAndReturnNewContactSiteDetailsPublishJob($siteId,$fldContact,$user);
                    FlowerDriveContactSite::create($preparedContactListSiteData);
                }
                if($bmDetailsType && $bmDetailsType == 'ORG'){
                    foreach ($bmOrgIdDetails as $bmOrgIdDetail) {
                        $siteId = Territory::select('terr_id')->where('terr_tenant_id', $orgId)->first()->terr_id;
                        $isBmOrgIdDetailAvailable = FlowerDriveByondMarketContact::query()->where('fld_id', $fldId)->where('fld_contact_id', $fldContact->id)->where('bm_org_detail_id', $bmOrgIdDetail)->first();
                        if ($isBmOrgIdDetailAvailable == null) {
                            $preparedBMContactListData = $this->_extractAndReturnNewBMContactDetailsPublishJob($fldId, $bmOrgIdDetail, $siteId, $fldContact, $requestParams, $contact,$user);
                            $savedData = FlowerDriveByondMarketContact::create($preparedBMContactListData);
                            if(!in_array($savedData->id,$contactIdsListForEmails)){
                                $contactIdsListForEmails[] = $savedData->id;
                            }
                        }
                    }
                }
                if($bmDetailsType && $bmDetailsType == 'BRNCH'){
                    foreach ($bmOrgIdDetails as $bmOrgIdDetail){
                        foreach ($siteIdList as $siteId) {
                            $isBmOrgIdDetailAvailable = FlowerDriveByondMarketContact::query()->where('fld_id', $fldId)->where('fld_contact_id', $fldContact->id)->where('bm_org_detail_id', $bmOrgIdDetail)->where('terr_id', $siteId)->first();
                            if ($isBmOrgIdDetailAvailable == null) {
                                $preparedBMContactListData = $this->_extractAndReturnNewBMContactDetailsPublishJob($fldId, $bmOrgIdDetail,$siteId, $fldContact, $requestParams, $contact,$user);
                                $savedData = FlowerDriveByondMarketContact::create($preparedBMContactListData);
                                if(!in_array($savedData->id, $contactIdsListForEmails)){
                                    $contactIdsListForEmails[] = $savedData->id;
                                }
                            }
                        }
                    }
                }
                if($bmDetailsType && $bmDetailsType == 'LOGI'){
                    foreach ($bmOrgDetails as $bmOrgDetail){
                        $preparedBMContactListData = $this->_extractAndReturnNewBMContactDetailsPublishJob($fldId, $bmOrgDetailId,$bmOrgDetail['territory_id'], $fldContact, $requestParams, $contact,$user);
                        $savedData =FlowerDriveByondMarketContact::create($preparedBMContactListData);
                        if(!in_array($savedData->id,$contactIdsListForEmails)){
                            $contactIdsListForEmails[] = $savedData->id;
                        }

                    }
                }
            }
            return $contactIdsListForEmails;
        }
    }
    private function _prepareContactListDataArray($requestParams,$isFldResend=false,$fldId=null,$isPrint=false)
    {
        $site_ids = [];
        if($requestParams['terrIds'] == 'undefined' ){
            $siteIdList = json_decode($requestParams['sites'], true);
            foreach ($siteIdList as $site)
            {
                array_push($site_ids,$site['terr_id']);
            }
        }else if(!isset($requestParams['terrIds'])){
            $fldSitesList =  FlowerDriveSite::select('terr_id')->where('fld_id',$fldId)->get()->toArray();
            foreach ($fldSitesList as $site)
            {
                array_push($site_ids,$site['terr_id']);
            }
        }else{
            $siteIdList = explode(",",($requestParams['terrIds']));
            if(!empty($siteIdList)){
                foreach ($siteIdList as $site)
                {
                    array_push($site_ids, json_decode($site));
                }
            }
        }
        return [ 'searchOptions' => [
            'terr_ids' => !empty($site_ids) ?$site_ids : '',
            'is_logical' => true,
            'is_active' => 1,
            'has_email' => 1,
            'has_physical_address' => 1,
        ],
            'terr_ids' => !empty($site_ids) ?$site_ids : '',
            'isFldResend' => $isPrint ? true:$isFldResend == true ? true : false,
            'fldId' => !empty($requestParams['flower_drive_id'])?$requestParams['flower_drive_id']:$fldId,
            'search' =>  !empty($requestParams['search']) &&  $requestParams['search'] != '' ? $requestParams['search'] : '',
        ];
    }
    private function getBmDetailType($bmOrgDetailId)
    {
        $bmDetailsType = BMarketOrgSettingDetails::select('BM_ORG_SETTINGS_TB.territory_type')->leftJoin('BM_ORG_SETTINGS_TB', 'BM_ORG_SETTING_DETAILS_TB.bm_org_id', '=', 'BM_ORG_SETTINGS_TB.bm_org_id')->where('BM_ORG_SETTING_DETAILS_TB.org_detail_id',$bmOrgDetailId)->first();
        return $bmDetailsType->territory_type;
    }
    private function _extractAndReturnNewContactDetailsPublishJob($contacts,$draftFlowerDriveId,$user)
    {
        return array_merge([
            'id' => gen_uuid() ,
            'contact_id' => $contacts->m_person_id,
            'fld_id' =>$draftFlowerDriveId
        ], $this->getCommonValuesForFld($user));
    }
    private function getCommonValuesForFld($user)
    {
        $commonValues = $this->getNewDBRowCreateCommonValuesForFld($user);

        unset($commonValues['org_id']);
        unset($commonValues['tnt_id']);
        unset($commonValues['row_version']);

        return $commonValues;
    }
    function getNewDBRowCreateCommonValuesForFld($user)
    {
        $user = isset($user) && $user != null?$user : getLoggedInUser();
        return [
            'org_id' => $user->user_tenant_id,
            'tnt_id' => null,
            'created_by' => $user->id,
            'created_at' => date('Y-m-d h:i:s'),
            'updated_by' => $user->id,
            'updated_at' => date('Y-m-d h:i:s'),
            'row_version' => time()
        ];
    }
    private function _extractAndReturnNewContactSiteDetailsPublishJob($siteId,$fldContact,$user)
    {
        return array_merge([
            'terr_id' => $siteId,
            'fld_contact_id' =>$fldContact->id
        ], $this->getCommonValuesForFld($user));
    }
    public function getContactChunkForLaunchAndPrint($fldId,$tenantId,$isAccountOwner,$user)
    {

        $fldRequiredDetails = FlowerDrive::query()->where('fld_id', $fldId)->select( 'org_id','contact_list_id','contact_count')->first();
        $contactListId = isset($fldId)?$fldRequiredDetails->contact_list_id:null;
        $contactCount = isset($fldId)?$fldRequiredDetails->contact_count:null;
        $contactList = collect();
        $option = $this->_prepareContactListDataArray(null,false,$fldId,true);
        if($contactListId ){
            $contactList = $this->contactListRepo->getContactListForPreview($option, $contactListId,'',null, $user,$tenantId,$isAccountOwner,$isActivateFld=true, $getListWithoutPagin = true, $fldId, 1);
            $contactListCount = $contactList['contactListCount'];
            if($contactListCount > 0){
                $newContactCount = $contactListCount+$contactCount;
                FlowerDrive::where('fld_id', $fldId)->update( ['contact_count' =>$newContactCount]);
            }
        }
       return ['contactList' => $contactList,
           'option' => $option
           ];

    }
    private function _extractAndReturnNewBMContactDetailsPublishJob($fldId,$bmOrgDetailId,$siteId,$fldContact,$requestParams,$person,$user)
    {
        return array_merge([
            'id' => gen_uuid() ,
            'bm_org_detail_id' =>$bmOrgDetailId,
            'fld_contact_id' => $fldContact->id,
            'contact_email' => isset($person->email) ? $person->email : null,
            'email_template_id' => $requestParams['templateId'],
            'paper_template_id' => $requestParams['paperTemplateId'] == null || $requestParams['paperTemplateId'] == "null" ? null : $requestParams['paperTemplateId'],
            'terr_id' =>$siteId,
            'fld_id' =>$fldId
        ], $this->getCommonValuesForFld($user));
    }
    public function getResendContactListForFlowerDriveLaunch($fldDriveId=null,$request,$siteIds=null, $user=null,$tenantId=null,$isAccountOwner=null,$isPrint=false){
        $requestParams = $request;
        $fldId =  $fldDriveId;
        // for FLD contacts details
        $fldRequiredDetails =    FlowerDrive::query()->where('fld_id', $fldId)->select( 'org_id','contact_list_id')->first();
        $orgId = $fldRequiredDetails->org_id;
        $contactListId = isset($fldId)?$fldRequiredDetails->contact_list_id:$contactListId = $request['contactId'];

        $contactList = null;
        $bmOrgIdDetails =[];
        $siteIdList =[];
        $option = $this->_prepareContactListDataArray($requestParams,true,$fldId,$isPrint);
        $contactList = $this->contactListRepo->getContactListForPreview($option, $contactListId,'',null, $user,$tenantId,$isAccountOwner, $isActivateFld=true, $getListWithoutPagin = true, $fldId, 1);
        //get BM details
        $bmOrgDetails = $this->organizationSettingsRepo->getOrgSettingsData($orgId,$siteIds);
        $bmOrgDetailId = $bmOrgDetails &&  isset($bmOrgDetails[0]->org_detail_id)?$bmOrgDetails[0]->org_detail_id:'';
        array_push($bmOrgIdDetails,$bmOrgDetailId);
        $bmDetailsType = $this->getBmDetailType($bmOrgDetailId);

        // add details of BM and FLD contacts again
        if ($contactList != null && isset($contactList)) {
            $contactList = new ContactListResourcesCollection($contactList);
            foreach ($contactList as $contact){
                $siteIdList = [];
                //select site ids from decease
                if($contact->relationships){
                    $relationships =  $contact->relationships;
                    foreach ($relationships as $relationship){
                        if($relationship->deceasedRelatedPerson && $relationship->deceasedRelatedPerson->deceasedPersonLocation && $relationship->deceasedRelatedPerson->deceasedPersonLocation->site){
                            array_push($siteIdList,$relationship->deceasedRelatedPerson->deceasedPersonLocation->site->terr_id);
                        }
                    }
                }
                // if ContactList has record with Right Holder
                if ($contact->rightsHolders) {
                    $rightHolderDeceased = $contact->rightsHolders;
                    foreach ($rightHolderDeceased as $rightHolder) {
                        if ($rightHolder->deceasedLocation) {
                            foreach ($rightHolder->deceasedLocation as $deceased) {
                                if ($deceased->site && $deceased->site->terr_id) {
                                    array_push($siteIdList, $deceased->site->terr_id);
                                }
                            }
                        }
                    }
                }
                // remove duplicates from $siteIdList
                $siteIdList = array_unique($siteIdList);
                $preparedContactListData = $this->_extractAndReturnNewContactDetailsPublishJob($contact,$fldId,$user);
                $fldContact= FlowerDriveContact::create($preparedContactListData);
                foreach ($siteIdList as $siteId){
                    if($bmOrgDetailId){
                        FlowerDriveSite::where('fld_id', $fldId)->where('terr_id', $siteId)->update( ['bm_org_detail_id' =>$bmOrgDetailId]);
                    }
                    $preparedContactListSiteData = $this->_extractAndReturnNewContactSiteDetailsPublishJob($siteId,$fldContact,$user);
                    FlowerDriveContactSite::create($preparedContactListSiteData);
                }
                if($bmDetailsType && $bmDetailsType == 'ORG'){
                    foreach ($bmOrgIdDetails as $bmOrgIdDetail) {
                        $siteId = Territory::select('terr_id')->where('terr_tenant_id', $orgId)->first()->terr_id;
                        $isBmOrgIdDetailAvailable = FlowerDriveByondMarketContact::query()->where('fld_id', $fldId)->where('fld_contact_id', $fldContact->id)->where('bm_org_detail_id', $bmOrgIdDetail)->first();
                        if ($isBmOrgIdDetailAvailable == null) {
                            $preparedBMContactListData = $this->_extractAndReturnNewBMContactDetailsPublishJob($fldId, $bmOrgIdDetail, $siteId, $fldContact, $requestParams, $contact,$user);
                            FlowerDriveByondMarketContact::create($preparedBMContactListData);
                        }
                    }
                }
                if($bmDetailsType && $bmDetailsType == 'BRNCH'){
                    foreach ($bmOrgIdDetails as $bmOrgIdDetail){
                        foreach ($siteIdList as $siteId) {
                            $isBmOrgIdDetailAvailable = FlowerDriveByondMarketContact::query()->where('fld_id', $fldId)->where('fld_contact_id', $fldContact->id)->where('bm_org_detail_id', $bmOrgIdDetail)->where('terr_id', $siteId)->first();
                            if ($isBmOrgIdDetailAvailable == null) {
                                $preparedBMContactListData = $this->_extractAndReturnNewBMContactDetailsPublishJob($fldId, $bmOrgIdDetail,$siteId, $fldContact, $requestParams, $contact);
                                FlowerDriveByondMarketContact::create($preparedBMContactListData);
                            }
                        }
                    }
                }
                if($bmDetailsType && $bmDetailsType == 'LOGI'){
                    foreach ($bmOrgDetails as $bmOrgDetail){
                        $preparedBMContactListData = $this->_extractAndReturnNewBMContactDetailsPublishJob($fldId, $bmOrgDetailId,$bmOrgDetail['territory_id'], $fldContact, $requestParams, $contact);
                        FlowerDriveByondMarketContact::create($preparedBMContactListData);

                    }
                }
            }
        }
        $contactList =  FlowerDriveByondMarketContact::query()->where('fld_id', $fldId)->get();
        return $contactList;
    }
    public function updateSelectedColumnsForFld($updateData, $fldDriveId)
    {
            $isUpdated = false;
            if (!empty($updateData)) {
                $isUpdated =  FlowerDrive::where('fld_id', $fldDriveId)->update($updateData);
            }
            return $isUpdated;
    }
    public function getContactListJobStatus($fldId)
    {
        $allocatedStatus = '';
        $status = FlowerDrive::select('con_list_job')
            ->where('fld_id', $fldId)
            ->first();
        if (!empty($status)) {
            $allocatedStatus = $status->con_list_job;
        }

        return $allocatedStatus;
    }

    public function createFldPaperTemplateChunkListByFldId($fldId, $contactList)
    {
        $chunkSize = env('FLD_PAPER_TEMPLATE_PRINT_CHUNK_SIZE', 50);
        $chunkPage = 1;
        $pageContactCount = 0;
        $chunkStart = 0;
        $chunkIdList = [];

        //Update FLD TB chunk status
        $addedLastChunk = FldPaperTemplatePdfChunk::where('fld_id',$fldId)->orderBy('created_at', 'desc')->first();
        if($addedLastChunk){
            $chunkStart = $addedLastChunk->chunk_range_end;
        }
        foreach ($contactList as $key=>$contact){
            $chunkStart = $chunkStart + 1;
            $chunkEnd = $chunkStart - 1 + $chunkSize;
            $pageContactCount ++;

            //Insert chunk row
            if($pageContactCount == 1) {
                $chunkData = [
                    'fld_id' => $fldId,
                    'chunk_range_start' => $chunkStart,
                    'chunk_range_end' => $chunkEnd,
                    'pdf_generate_status' => "PENDING",
                ];
                $chunkDetails = FldPaperTemplatePdfChunk::create($chunkData);
                $fldPaperTemplatePdfId = $chunkDetails['fld_paper_template_pdf_id'];
                $chunkIdList[] = $chunkDetails['fld_paper_template_pdf_id'];
            }

            if($fldPaperTemplatePdfId) {
                //Update BM contacts table
                $bmContactWhere = [
                    'id' => $contact->id,
                    'fld_id' => $fldId,
                ];
                $bmContactUpdatedData = FlowerDriveByondMarketContact::where($bmContactWhere)
                ->update(['fld_paper_template_pdf_id' => $fldPaperTemplatePdfId]);
            }

            if($pageContactCount == $chunkSize)
            {
                $pageContactCount = 0;
                $chunkPage ++;
            }
        }
        return $chunkIdList;
    }

    public function getFlowerDriveContactListForPaperTemplateChunkCreate($fldId, $options = [], $start = '', $end = '', $list_no = 1)
    {
        $chunk_size = env('FLD_EMAIL_API_BLOCK_SIZE', 50);
        if ($start != '' && $end != '') {
            $chunk_size = ((int)$end + 1) - (int)$start;
        }
        $contactList = FlowerDriveByondMarketContact::select('id','fld_id','terr_id','contact_email','email_template_id','email_sent_count', 'fld_contact_id')->with(['Territory'=> function($q){
            $q->select('terr_name','terr_id','contact_email');
        }, 'fldContacts' => function($q) {
            $q->select('id', 'contact_id')->with('person.relationships.deceasedRelatedPerson');
        }])
            ->where('fld_id', '=', $fldId)
            ->whereNull('fld_paper_template_pdf_id');

        $allContactList = $contactList->get();
        if (!empty($options['paginate'])) {
            $contactList = $contactList->paginate($options['paginate']*$chunk_size);
        } else {
            $contactList = $contactList->paginate($contactList->count());
        }
        $returnData = [];
        $returnData['contactList'] = $contactList;
        $returnData['allContactList'] = $allContactList;
        return $returnData;
    }

    public function getAllFlowerDriveContactListForPaperTemplateChunk($options, $fldId)
    {
        $userTenantId = empty($tenantId) ? getLoggedInUser()->user_tenant_id : $tenantId;
        $chunkList = FldPaperTemplatePdfChunk::select('fld_paper_template_pdf_id', 'chunk_range_start', 'chunk_range_end', 'pdf_s3_url', 'pdf_generate_status', 'fld_paper_template_archive_url_id', 'file_size')
            ->where('fld_id', $fldId);

        if (array_key_exists('sortBy', $options) && !empty($options['sortBy'])) {

        }
        $chunkList = $this->_searchChunksRawSql($chunkList, $options, $userTenantId);
        if (!empty($options['paginate'])) {
            $chunkList = $chunkList->paginate($options['paginate']);
        } else {
            $chunkList = $chunkList->paginate($chunkList->count());
        }
        return $chunkList;
    }

    public function getSelectedDataForFlowerDrive($selectedColumnsArr, $fldId)
    {
        $fldDetails = [];
        if (!empty($selectedColumnsArr)) {
            $fldDetails = FlowerDrive::addSelect($selectedColumnsArr)->where('fld_id', $fldId)->first();
        }
        return $fldDetails;
    }

    public function createFldPaperTemplateChunkList($fldId)
    {
        $contactList = $this->getFlowerDriveContactListForPaperTemplateChunk($fldId, true);
        return $contactList;
    }

    public function getAllFldPaperTemplateArchieveData($fldId, $options = [])
    {
        $data = FldPaperTemplateArchiveUrl::where('fld_id', $fldId);
        if ($options['isPaginate'] == false) {
            $data = $data->get();
        } else {
            $data = $data->paginate($options['paginate']);
        }
        return $data;
    }

    public function getFlowerDriveContactListForPaperTemplateChunk($fldId,$forChunk = false, $isEexcludePaperTempEmailAddressDefined = false)
    {
        $contactList = FlowerDriveByondMarketContact::select('id','fld_id','terr_id','contact_email','email_template_id','email_sent_count', 'fld_contact_id')->with(['Territory'=> function($q){
            $q->select('terr_name','terr_id','contact_email');
        }, 'fldContacts' => function($q) {
            $q->select('id', 'contact_id')->with('person.relationships.deceasedRelatedPerson');
        }, 'flowerDrive' => function ($q) {
            $q->select('fld_id', 'contact_list_id')->with(['getSelectedContactList' => function ($q) {
                $q->select('id', 'is_physical_address_must');
            }]);
            }])
            ->where('fld_id', '=', $fldId)
            ->whereNull('fld_paper_template_pdf_id');
        $contactList = $contactList->whereHas('flowerDrive.getSelectedContactList', function ($query) {
            $query->where('is_physical_address_must', 1);
        });

        //Functionality to allow the users to exclude Paper Brochures to contacts that already receive through Email
        if($isEexcludePaperTempEmailAddressDefined) {
            $contactList = $contactList->whereNull('contact_email');
        }
        $contactList = $contactList->whereHas('fldContacts.person.primaryAddress', function ($query) {
            $query->whereNotNull('address1')
                ->whereNotNull('rk_region')
                ->whereNotNull('rk_state')
                ->whereNotNull('rk_country')
                ->whereNotNull('rk_postal_code');
        });
        $contactList = $contactList->whereHas('fldContacts.person', function ($query) {
            $query->where('opt_for_communication', '=', 1);
        });

        if(!$forChunk) {
            $contactList = $contactList->get();
        }
        return $contactList;
    }

    public function getFldPaperTemplateContactList($fldId)
    {
        $contactList = $this->getFlowerDriveContactListForPaperTemplateChunk($fldId, true);
        return $contactList;
    }

    public function getFlowerDriveContactListByChunkId($fldId, $fldPaperTemplatePdfId)
    {
        $contactList = FlowerDriveByondMarketContact::select('id','fld_id','terr_id','contact_email','email_template_id','email_sent_count', 'fld_contact_id')->with(['Territory'=> function($q){
            $q->select('terr_name','terr_id','contact_email');
        }, 'fldContacts' => function($q) {
            $q->select('id', 'contact_id')->with('person.relationships.deceasedRelatedPerson');
        }])
            ->where('fld_id', '=', $fldId)
            ->where('fld_paper_template_pdf_id', $fldPaperTemplatePdfId);
        return $contactList->get();
    }
    /**
     * New function for send flower drive emails for contacts
     *
     * @param $fldId
     * @param $orgType
     * @param $resendContactIds
     * @return void
     */
    public function _sendFlowerDriveEmailsForContacts($fldId, $orgType, $resendContactIds = null,$option = null,$contactIdsListForEmails=[],$executedChunkedJobCount=null,$chunksCount=null,$selectedEmailTemplate = null,$userEmail=null,$userName=null,$userId=null)
    {
        $orgId = $this->makeModel()->where('fld_id', $fldId)->select('org_id')->first()->org_id;
        $chunkIndex = 0;
        $contactStatus = isset($selectedEmailTemplate->contact_status) ? $selectedEmailTemplate->contact_status: 'NOT_SENT';

        try {
            $templateDetails = $this->emailTemplateRepo->getEmailcontentforSendEmail($fldId);

            $flowerDriveLine = new FlowerDriveLine();

            if (isset($templateDetails) && $templateDetails != null && $templateDetails[0]) {
                $emailBody['flower_drive_name'] = $templateDetails[0]->fld_name ? $templateDetails[0]->fld_name : '';
                $emailBody['flower_drive_description'] = $templateDetails[0]->fld_desc ? $templateDetails[0]->fld_desc : '';
                $emailBody['order_by_date'] = $templateDetails[0]->order_by_date ? date('d-F-Y', strtotime( $templateDetails[0]->order_by_date )): '';
                $emailBody['cancel_by_date'] = $templateDetails[0]->cancel_by_date ?  date('d-F-Y', strtotime( $templateDetails[0]->cancel_by_date )) : '';
                $emailBody['special_occasion'] = $templateDetails[0]->specialOccassion && $templateDetails[0]->specialOccassion->occ_name ? $templateDetails[0]->specialOccassion->occ_name : '';
                $emailBody['special_occasion_date'] = $templateDetails[0]->specialOccassion && $templateDetails[0]->specialOccassion->occ_date ? date('d-F-Y', strtotime( $templateDetails[0]->specialOccassion->occ_date )): '';
                $emailSubject = $templateDetails[0]->fld_name;

                // set web pages array
                $webPageDetails = [];
                if (isset($templateDetails[0]->flowerDriveSite) && $templateDetails[0]->flowerDriveSite != null) {
                    if ($orgType == 'ORG') {
                        $webPage = FlowerDriveSite::where('fld_id', '=', $fldId)->first()->web_page;
                        $webPageDetails[] = [
                            'web_page' => $webPage ? $webPage : ''
                        ];
                    } else {
                        foreach ($templateDetails[0]->flowerDriveSite as $site) {
                            $web_arr_key = $site->terr_id;
                            $webPageDetails[$web_arr_key] = [
                                'web_page' => $site->web_page ? $site->web_page : ''
                            ];
                        }
                    }
                }
                // set fld lines (items) array
                if (isset($templateDetails[0]->lines) && $templateDetails[0]->lines != null) {
                    $itemDetails = [];
                    foreach ($templateDetails[0]->lines as $lines) {
                        if ($lines->is_pkg == 0) {
                            $path = $flowerDriveLine->getImageAttribute($lines->item_id, IMAGE_RESOURCE_TYPE_ITEM_MASTER_GALLERY);
                        } else {
                            $path = $flowerDriveLine->getImageAttribute($lines->pkg_id, IMAGE_RESOURCE_TYPE_FLOWER_DRIVE_PACKAGES_GALLERY);
                        }

                        if (is_array($path) && array_key_exists(0, $path)) {
                            $imagePath = $path[0]['image_crops']['medium'];
                        } else {
                            $imagePath = $path['url'];
                        }

                        $linePrices = [];
                        if ($orgType == 'ORG') {
                            foreach ($lines['lineItemPrice'] as $lineItemPrice) {
                                if ($lineItemPrice->fld_line_id == $lines->fld_line_id) {
                                    $currency = $lineItemPrice->currency;
                                    $arr_key = $lineItemPrice->fld_line_id;

                                    $linePrices[] = $lineItemPrice->total_extended_price;
                                    $linePrices = collect($linePrices);
                                    $minPrice = $linePrices->min();
                                    $maxPrice = $linePrices->max();

                                    if ($minPrice == $maxPrice) {
                                        $itemPrice = $currency . " " . priceFormat($minPrice, 2);
                                    } else {
                                        $itemPrice = $currency . " " . priceFormat($minPrice, 2) . " - " . $currency . " " . priceFormat($maxPrice, 2);
                                    }

                                    $itemDetails[$arr_key] = [
                                        'item_name' => $lines->line_name ? $lines->line_name : '',
                                        'item_description' => $lines->line_desc ? $lines->line_desc : '',
                                        'item_price' => $itemPrice,
                                        'item_image' => isset($imagePath) ? $imagePath : null,
                                    ];
                                }
                            }
                        } elseif ($orgType == 'BRNCH') {
                            $itemPrices = [];
                            foreach ($lines['lineItemPrice'] as $lineItemPrice) {
                                if ($lineItemPrice->fld_line_id == $lines->fld_line_id) {
                                    $currency = $lineItemPrice->currency;
                                    $arr_key = $lineItemPrice->fld_line_id;
                                    $itemPrice = $lineItemPrice->total_extended_price;
                                    $terr_id = $lineItemPrice->terr_id;
                                    $itemPrices[$terr_id] = $currency . " " . priceFormat($itemPrice, 2);
                                    $itemDetails[$arr_key] = [
                                        'item_name' => $lines->line_name ? $lines->line_name : '',
                                        'item_description' => $lines->line_desc ? $lines->line_desc : '',
                                        'item_price' => $itemPrices,
                                        'item_image' => isset($imagePath) ? $imagePath : null,
                                        'terr_id' => $terr_id
                                    ];
                                }
                            }
                        }  else if ($orgType == 'LOGI') {
                            $itemPrices = [];
                            foreach ($lines['lineItemPrice'] as $lineItemPrice) {
                                if ($lineItemPrice->fld_line_id == $lines->fld_line_id) {
                                    $currency = $lineItemPrice->currency;
                                    $arr_key = $lineItemPrice->fld_line_id;
                                    $linePrices[] = $lineItemPrice->total_extended_price;
                                    $linePrices = collect($linePrices);
                                    $minPrice = $linePrices->min();
                                    $maxPrice = $linePrices->max();
                                    if ($minPrice == $maxPrice) {
                                        $itemPrice = $currency . " " . priceFormat($minPrice, 2);
                                    } else {
                                        $itemPrice = $currency . " " . priceFormat($minPrice, 2) . " - " . $currency . " " . priceFormat($maxPrice, 2);
                                    }
                                    $terr_id = $lineItemPrice->terr_id;
                                    $itemDetails[$arr_key] = [
                                        'item_name' => $lines->line_name ? $lines->line_name : '',
                                        'item_description' => $lines->line_desc ? $lines->line_desc : '',
                                        'item_price' => $itemPrice,
                                        'item_image' => isset($imagePath) ? $imagePath : null,
                                        'terr_id' => $terr_id
                                    ];
                                }
                            }
                        }
                    }
                }

                $emailBody['item_images'] = $itemDetails;

                // get email template details
                $defaultTemplateId =  EmailTemplate::select('template_id')->where(['is_sys_defined'=> 1,'template_status'=> 1,'deleted_at'=> NULL])->first()->template_id;
                $email_template_id = isset($selectedEmailTemplate->template_id)  ? $selectedEmailTemplate->template_id : $defaultTemplateId;
//                $email_template_id = isset($templateDetails[0]->email_template_id) ? $templateDetails[0]->email_template_id : 1; //should add default template id here.
                $templateDetail = $this->emailTemplateRepo->getTemplateDetailsById($email_template_id);

                $templateHtml = html_entity_decode($templateDetail[0]['template_details']);
                $isContactListUpdated = false;

                // get contact list
                if (isset($resendContactIds)) {
                    // for resend emails
                    Log::info('resend emails to contacts');
                    $contactList = FlowerDriveByondMarketContact::select('id', 'fld_id', 'fld_contact_id', 'terr_id', 'contact_email', 'email_template_id', 'email_sent_count')
                        ->with([
                            'Territory' => function ($q) {
                                $q->select('terr_name', 'terr_id', 'contact_email');
                            },
                            'fldContacts' => function ($q) {
                                $q->select('id', 'contact_id')->with('person.relationships.deceasedRelatedPerson')->whereHas('person', function ($query) {
                                    $query->where('opt_for_communication', 1);
                                });
                            }
                        ])
                        ->where(['fld_id'=> $fldId])
                        ->where(['contact_status'=> $contactStatus])
                        ->whereNotNull('contact_email')
                        ->whereIn('fld_contact_id', $resendContactIds)
                        ->whereHas('fldContacts.person', function ($query) {
                            $query->where('opt_for_communication', 1);
                        });
                    if (!empty($contactIdsListForEmails)) {
                        $isContactListUpdated = true;
                        $contactList = $contactList->whereIn('id',$contactIdsListForEmails);
                    }
                } else {
                    // for send emails
                    Log::info('send emails to contacts');
                    $contactList = FlowerDriveByondMarketContact::select('id', 'fld_id', 'fld_contact_id', 'terr_id', 'contact_email', 'email_template_id', 'email_sent_count')
                        ->with([
                            'Territory' => function ($q) {
                                $q->select('terr_name', 'terr_id', 'contact_email');

                            },
                            'fldContacts' => function ($q) {
                                $q->select('id', 'contact_id')->with('person.relationships.deceasedRelatedPerson')->whereHas('person', function ($query) {
                                    $query->where('opt_for_communication', 1);
                                });
                            },
                            'flowerDrive' => function ($q) {
                                $q->select('fld_id', 'contact_list_id')->with(['getSelectedContactList' => function ($q) {
                                    $q->select('id', 'is_email_address_must');
                                }]);
                            }
                        ])
                        ->whereNotNull('contact_email');
                        if($contactStatus == 'NOT_SENT'){
                            $contactList = $contactList->where( 'is_email_sent',0);
                        }
                        $contactList = $contactList->where(['fld_id' => $fldId,'contact_status'=>$contactStatus])
                            ->whereHas('fldContacts.person', function ($query) {
                            $query->where('opt_for_communication', 1);
                        });
                    $contactList = $contactList->whereHas('flowerDrive.getSelectedContactList', function ($query) {
                        $query->where('is_email_address_must', 1);
                    });

                    if (!empty($contactIdsListForEmails)) {
                        $isContactListUpdated = true;
                        $contactList = $contactList->whereIn('id',$contactIdsListForEmails);
                    }
                }
                //  $contactListChunkCount contains the count of the chunked $contactList
                $contactListChunkCount = $contactList->count();
                $chunkSize = env('EMAIL_MODE', 'smtp') == 'smtp' ? env('FLD_EMAIL_SMTP_BLOCK_SIZE', 20) : env('FLD_EMAIL_API_BLOCK_SIZE', 50);
                $chunksCountForEmails = ceil($contactListChunkCount / $chunkSize);
                $executedChunkedJobCountForEmails = 0;

                if($contactListChunkCount>0) {
                    // chunk contact list and send for fld send email job
                    $contactList->chunk($chunkSize,
                        function ($contactList) use ($templateHtml, $webPageDetails, $emailBody, $emailSubject, &$chunkIndex, $orgId, $orgType,$fldId,&$executedChunkedJobCount,$chunksCount,$chunksCountForEmails,&$executedChunkedJobCountForEmails, $isContactListUpdated,$userEmail,$userName,$selectedEmailTemplate,$userId) {
                        $chunkIndex++;
                        $queue_name = null;

                        if ($chunkIndex == 1) {
                            $delay_time = 0;
                        } else {
                            $delay_time = env('EMAIL_MODE', 'smtp') == 'smtp' ? env('FLD_EMAIL_SMTP_DELAY_TIME', 120) : env('FLD_EMAIL_API_DELAY_TIME', 10);
                        }

                        if ($contactList->isNotEmpty()) {
                            $serializedData = $this->serializeData($contactList);
                            $filename = 'fld_email_contact/contacts.txt';
                            $this->clearAndWriteToFile($filename, $serializedData);
                        }

                        $executedChunkedJobCountForEmails++;
                            $job = new FlowerDriveChunkEmailNewJob($contactList, $templateHtml, $webPageDetails, $emailBody, $emailSubject, $orgId, $orgType,$executedChunkedJobCount,$chunksCount,$chunksCountForEmails,$executedChunkedJobCountForEmails,$fldId, $isContactListUpdated,$userEmail,$userName,$selectedEmailTemplate,$userId);

                        if (env('EMAIL_MODE', 'smtp') == 'smtp') {
                            $queue_name = 'FlowerDrive-Email';
                        } else {
                            if ($chunkIndex % 3 == 0) {
                                $queue_name = 'FlowerDrive-Email1';
                            } elseif ($chunkIndex % 3 == 1) {
                                $queue_name = 'FlowerDrive-Email2';
                            } else {
                                $queue_name = 'FlowerDrive-Email3';
                            }
                        }

                        if (isset($queue_name)) {
                            dispatch($job)->delay(now()->addSeconds($delay_time))->onQueue($queue_name);
                        }
                        Log::info('email chunk set number', (array)$chunkIndex);
                    });

                } else {
                    $this->updateSelectedColumnsForFld(['con_list_job' => 'COMPLETED'],  $fldId );
                    //send confirmation mail
                    if($userEmail != null){
                        $fldName = FlowerDrive::select('fld_name')->where('fld_id',$this->fldId)->pluck('fld_name')->first();
                        $email = new FldEmailConfirmation( $fldName,$userName);
                        Mail::to($userEmail)->send($email);
                    }
                }


            }
        } catch (\Exception $ex) {
            Log::error($ex->getMessage());
        }
    }

    private function clearAndWriteToFile($filename, $content)
    {
        Storage::put($filename, $content);
        return true;
    }
    private function serializeData($data)
    {
        return serialize($data);
    }

    public function getSectionsBySites($selectedSites = [])
    {
        $siteListArray = $this->_createQueryArray($selectedSites);
        $sectionList = BMarketSectionOrArea::select('sec_or_area_id', 'territory_id', 'section_or_area_name')->whereIn('territory_id', $siteListArray)->where('is_exclude_from_floral_program', 0)->get();
        return $sectionList;
    }

    private function _createQueryArray($selectedSites): array
    {
        $sites = [];

        if (!empty($selectedSites)) {
            foreach ($selectedSites as $site) {
                $sites[] = json_decode($site);
            }
        }
        return $sites;
    }

    public function getSectionDataToSave($section_id)
    {
        return BMarketSectionOrArea::select('sec_or_area_id', 'territory_id', 'section_or_area_name')->where('sec_or_area_id', $section_id)->first();
    }

    public function saveSectionData($sectionData)
    {
        $data = FldSections::create($sectionData);
    }

    public function deleteSectionDataBeforeUpdate($fld_id)
    {
        $data = FldSections::where('fld_id', $fld_id)->delete();
    }

    public function getFldType($fld_id)
    {
        $data = FlowerDrive::select('fld_type')->where('fld_id', $fld_id)->first();
        return $data->fld_type;
    }

    public function unsubscribeFLDBMContract($fldContactId){
        if ($fldContactId){
            return FlowerDriveByondMarketContact::where('fld_contact_id',$fldContactId)
                ->update(['contact_status'=>UNSUBSCRIBED]);
        }else{
            return null;
        }
    }

}
