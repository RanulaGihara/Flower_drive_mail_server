<?php


namespace App\Modules\FlowerDrive\Repositories;



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


    $flowerDrivesQuery = $flowerDrivesQuery
    ->leftJoin('site_table', 'flower_drive_table.fld_id', '=', 'site_table.fld_id')
    ->leftJoin('territories_table', 'site_table.terr_id', '=', 'territories_table.terr_id')
    ->leftJoin('contact_table', 'flower_drive_table.fld_id', '=', 'contact_table.fld_id')
    ->leftJoin('special_occasion_table', 'special_occasion_table.occ_id', '=', 'flower_drive_table.special_oc_id')
    ->addSelect(DB::raw("GROUP_CONCAT(distinct territories_table.terr_name ORDER BY territories_table.terr_name ASC SEPARATOR ', ') terr_name"))
    ->addSelect(DB::raw("GROUP_CONCAT(distinct territories_table.terr_id ORDER BY territories_table.terr_id ASC SEPARATOR ',') terr_id"))
    ->addSelect(DB::raw("GROUP_CONCAT(distinct contact_table.id ORDER BY contact_table.id ASC SEPARATOR ',') id"))
    ->groupBy('flower_drive_table.fld_id', 'site_table.fld_id', 'special_occasion_table.occ_id');

if (array_key_exists('sortBy', $sortingOptions) && !empty($sortingOptions['sortBy'])) {
    if ($sortingOptions['sortBy']['column'] == '') {
        $flowerDrivesQuery = $flowerDrivesQuery->orderByRaw('IFNULL(flower_drive_table.updated_at, flower_drive_table.created_at) DESC');
    }
    if ($sortingOptions['sortBy']['column'] == 'fld_name') {
        $flowerDrivesQuery = $flowerDrivesQuery
            ->selectRaw('CAST(REGEXP_SUBSTR(fld_name,"[0-9]+") AS UNSIGNED) AS `concat_srt_fnName`')
            ->orderByRaw("REGEXP_SUBSTR(fld_name, '[a-z|A-Z]+') ".$sortingOptions['sortBy']['type'])
            ->orderBy("concat_srt_fnName", $sortingOptions['sortBy']['type']);
    }
    // Rest of the conditions...
    // (Please replace "$options" with "$sortingOptions" for consistency if it's used elsewhere)
}

    /**
     * @return mixed
     * get list of orders
     */
   
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


        }
        return $flowerDrives;
    }

private function _searchChunks($flowerDriveQuery, $options)
{
    $user = getLoggedInUser();
    $searchOptions = wildCardCharacterReplaced($options['search']);
    if (array_key_exists('columns', $options) && $options['columns'] != null) {
        $selectedColumn = array_flip($options['columns']);
    }
    if (array_key_exists('search', $options)) {
        $options['search'] = wildCardCharacterReplace($options['search']);
        $search = $options['search'];
        if ($options['filterForCopying'] == true) {
            if ($options['search'] != "" && isset($selectedColumn['fld_name'])) {
                $flowerDriveQuery = $flowerDriveQuery->where('flower_drive_table.fld_name', 'like', '%' . $searchOptions . '%')
                    ->whereIn('fld_status', [FLD_COMPLETED, FLD_PUBLISHED, FLD_ACTIVE])->where('fld_type', $options['fldType']);
            } else if ($options['search']) {
                $flowerDriveQuery = $flowerDriveQuery->whereRaw('0 != 0');
            }
            // Rest of the conditions...

            // (Please replace "$options" with "$sortingOptions" for consistency if it's used elsewhere)
        } else {
            $selectedColumn = isset($selectedColumn) ? $selectedColumn : null;
            $flowerDriveQuery->where(function ($query) use ($options, $searchOptions, $user, $selectedColumn, $search) {
                if ($options['search'] != "" && isset($selectedColumn['fld_name'])) {
                    $query = $query->where('flower_drive_table.fld_name', 'like', '%' . $searchOptions . '%')->where('fld_type', $options['fldType']);
                } else if ($options['search']) {
                    $query = $query->whereRaw('0 != 0');
                }
                // Rest of the conditions...
            });
        }
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
    
    private function iteToFile($filename, $content)
    {
        Storage::put($filename, $content);
        return true;
    }
    private function serializeData($data)
    {
        return serialize($data);
    }
    public function unsubscribe($fldContactId){
        if ($fldContactId){
            return FlowerDriveByondMarketContact::where('fld_contact_id',$fldContactId)
                ->update(['contact_status'=>UNSUBSCRIBED]);
        }else{
            return null;
        }
    }

}
