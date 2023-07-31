<?php

namespace App\Modules\FlowerDrive\Repositories;

use App\Consumer;
use App\ConsumerOrderItemLocation;
use App\FlowerDrivePurchaseOrderAllocation;
use App\FlowerDrivePurchaseOrderReceipt;
use App\FlowerDrivePurchaseOrderReceiptLine;
use App\FlowerDrivePurchaseOrderReturnLine;
use App\FlowerDriveServiceSchedule;
use App\FlowerDriveServiceScheduleLine;
use App\FlowerDriveServiceScheduleLineItem;
use App\Country;
use App\Mail\FldPurchaseOrderScheduleAllocationConfirmEmail;
use App\Region;
use App\Modules\Commonsequence\Contracts\CommonSequenceRepositoryInterface;

use App\ConsumerOrder;
use App\ConsumerOrderItem;
use App\FlowerDrive;
use App\PriceList;
use App\PriceListOptionAll;
use App\PriceListOptionItem;
use App\PriceListOptionItemType;
use App\Territory;
use App\FlowerDriveLine;
use App\FlowerDrivePurchaseOrder;
use App\Registration;
use App\FlowerDriveSite;
use App\FlowerDrivePurchaseOrderLine;
use App\Image;
use App\ItemMaster;
use App\FlowerDriveGeneratePurchaseOrderTemp;
use App\Modules\FlowerDrive\Contracts\FlowerDrivePurchaseOrderRepositoryInterface;
use App\Repositories\MainRepository;
use App\TimeZone;
use Barryvdh\Snappy\Facades\SnappyPdf as PDF;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
 use Illuminate\Support\Facades\Mail;
use phpDocumentor\Reflection\DocBlock\Description;
use stdClass;
use function GuzzleHttp\json_decode;
use App\FldPo;
use App\Tenant;
use App\CareProgramSupplierPermission;
use App\LocationTypes;


class FlowerDrivePurchaseOrderRepository extends MainRepository implements FlowerDrivePurchaseOrderRepositoryInterface
{

    // use ReleasePurchaseOrderEmail;
    const O_BR = '(';
    const C_BR = ')';
    const OP_OR = ' OR ';
    const OP_AND = ' AND ';
    // const item_type='';
    private $commonSequenceRepo;

    public function __construct(CommonSequenceRepositoryInterface $commonSequenceRepo)
    {
        $this->commonSequenceRepo = $commonSequenceRepo;
    }

    function model()
    {
        return 'App\FlowerDrivePurchaseOrder';
    }

    /**
     * Update email genrated date
     *
     * @param array $options
     *
     * @return mixed
     */
    public function updateEmailGenatedDateForPurchaseOrder($options)
    {
        $date = date('Y-m-d');
        $result = FlowerDrivePurchaseOrder::whereIn('fld_po_id', $options)
            ->update([
                'po_status' => 'Ordered',
                'released_date' => $date
            ]);
    }

    public function _generatePDF($previewPODetails, $logoBase64 = null, $user = null)
    {
        $status = false;
        $data = null;
        $pdfFileName = 'temp/Purchase Order' . rand(1, 10000000) . '.pdf';
        if (!empty($logoBase64)) {
            $previewPODetails['base64Content'] = $logoBase64;
            $data = $previewPODetails;
        } else {
            $previewPODetails['base64Content'] = getSiteLogo($previewPODetails['terr_id'], false, $previewPODetails['tena_type'], $user);
            $data = $previewPODetails;
        }
        try {
            $pdf = PDF::loadView('flowerdrive::flower_drive_purchase_order_print_pdf', $data);//dd($pdf);
            $pdf->setPaper('A4', 'portrait')->save($pdfFileName);
        } catch (Exception $e) {
            dd($e);
        }
        $status = true;
        $data = $pdfFileName;
        return $data;
    }


    public function _preparePODetailsDataArray($data, $user = null)
    {
        $items = [];
        foreach ($data->flowerDrivePurchaseOrderItems as $lineItem) {
            $noOfLocation = 0;
            $locationList = [];

            if (isset($lineItem->consumerOrderItems)) {
                foreach ($lineItem->consumerOrderItems as $order_item) {
                    foreach ($order_item->consumerOrderItemLocations as $location) {
                        $key = $lineItem->item->item_id . '-' . $location['location_id'];
                        if (!array_key_exists($key, $locationList)) {
                            $locationList[$key] = $key;
                        }
                    }
                }
            }
            $noOfLocation = count($locationList);

            $item = [
                "item_id" => $lineItem->item->item_id,
                "product" => $lineItem->item->item_name,
                "qty_ordered" => $lineItem['qty_ordered'],
                "price_per_unit" => $lineItem['unit_price'],
                "total" => $lineItem['extended_cost'],
                "no_of_locations" => $noOfLocation,
                "qty_outstanding" => $lineItem['qty_outstanding'],
            ];
            $item = (object) $item;
            array_push($items, $item);
        }
        $PODetails = [];
        $PODetails['po_no'] = $data['fld_po_no'] ? $data['fld_po_no'] : "";
        $PODetails['site_name'] = $data->territory->terr_name;
        $PODetails['site_logo'] = isset($data->territory['siteLogo']['image_crops']) ? (isset($data->territory['siteLogo']['image_crops']['large']) ? $data->territory['siteLogo']['image_crops']['large'] : '') : '';
        $PODetails['tena_type'] = $data->territory->tenant->tena_type;
        $PODetails['fld_po_id'] = $data->fld_po_id;
        $PODetails['po_no'] = $data->fld_po_no;
        $PODetails['flower_drive'] = $data->flowerDrive->fld_name;
        $PODetails['po_details'] = $items;
        $PODetails['total'] = $data->order_total;
        $PODetails['supplier_name'] = $data->supplier->tena_name;
        $PODetails['supplier_address_1'] = $data->supplier->registration->regi_address_1;
        $PODetails['supplier_address_2'] = $data->supplier->registration->regi_address_2;
        $PODetails['supplier_town'] = $data->supplier['registration']['region']['town_short_name'];
        $PODetails['supplier_state'] = $data->supplier['registration']['region']['countryState']['state_name'];
        $PODetails['supplier_country'] = $data->supplier['registration']['region']['country']['country_name'];
        $PODetails['supplier_post_code'] = $data->supplier['registration']['region']['postalCode']['postal_code'];
        $PODetails['supplier_contact_email'] = $data->supplier['parentTerritory']['contact_email'];
        $PODetails['supplier_contact_email'] = $data->supplier['parentTerritory']['contact_email'];
        $PODetails['po_date'] = $data->order_date;
        $PODetails['site_address_1']=$data->territory->address_1;
        $PODetails['site_address_2']=$data->territory->address_2;
        $PODetails['site_town']=$data->territory['region']['town_short_name'];
        $PODetails['site_state']=$data->territory['region']['countryState']['state_name'];
        $PODetails['site_post_code']=$data->territory['region']['postalCode']['postal_code'];
        $PODetails['site_tax_number']=$data->territory->tax_number;
        $PODetails['terr_id']=$data->territory->terr_id;
        $PODetails['site_country'] = isset($data->territory['region']['country']['country_name']) ? $data->territory['region']['country']['country_name'] : '';
        $PODetails['delivery_method']=$data->is_delivery_placement == 0 ? 'Delivery only' : 'Delivery with placement';
        $PODetails['del_to_name']=$data->deliveryTo->terr_name;
        $PODetails['del_to_address_1']=$data->deliveryTo->address_1;
        $PODetails['del_to_address_2']=$data->deliveryTo->address_2;
        $PODetails['del_to_town']=$data->deliveryTo['region']['town_short_name'];
        $PODetails['del_to_state']=$data->deliveryTo['region']['countryState']['state_name'];
        $PODetails['del_to_country']=$data->deliveryTo['region']['country']['country_name'];
        $PODetails['del_to_post_code']=$data->deliveryTo['region']['postalCode']['postal_code'];
        $PODetails['as_an_agent']=$data->as_an_agent;
        $PODetails['agent_company_name']=COMPANY_NAME;
        $PODetails['agent_company_address1']=COMPANY_ADDRESS1;
        $PODetails['agent_company_address2']=COMPANY_ADDRESS2;
        $PODetails['agent_company_town']=COMPANY_TOWN;
        $PODetails['agent_company_state']=COMPANY_STATE;
        $PODetails['agent_company_country']=COMPANY_COUNTRY;
        $PODetails['agent_company_post_code']=COMPANY_POST_CODE;
        $PODetails['currency']=$data->currency;
        $PODetails['is_delivery_placement']=$data->is_delivery_placement;
        $PODetails['primary_contact_site_name']=$data->territory->terr_name;
        $PODetails['primary_contact_first_name']=$data->supplier->registration->regi_surname;
        $PODetails['primary_contact_last_name']=$data->supplier->registration->contact_surname;
        $PODetails['primary_contact_phone']=$data->supplier['parentTerritory']['contact_phone'];
        $PODetails['primary_contact_email_address']=$data->supplier['parentTerritory']['contact_email'];
        $PODetails['preview_status']= false;

        if (!empty($user)) {
            $PODetails['base64Content'] = getSiteLogo($data->territory->terr_id, false, $PODetails['tena_type'], $user);
        } else {
            $PODetails['base64Content'] = getSiteLogo($data->territory->terr_id);
        }
        return $PODetails;
    }

    public function getAllOrders($options, $tenantId = null, $pluck = '', $limit = null)
    {
        $searchTerm = '';
        $user_tenant_id = empty($tenantId) ? getLoggedInUser()->user_tenant_id : $tenantId;
        $orders = FlowerDrivePurchaseOrder::select('FLD_PO_TB.number_of_orders', 'SYS_tenants.tena_name','FLD_PO_TB.currency', 'SYS_territories.terr_name', 'FLD_PO_TB.fld_po_id', 'FLD_TB.fld_id', 'FLD_TB.fld_name', 'FLD_PO_TB.fld_po_no', 'FLD_PO_TB.order_total', 'FLD_PO_TB.sup_by_date', 'FLD_PO_TB.po_status', 'FLD_TB.complete_by_date', 'FLD_TB.fld_type')
                    ->join('FLD_TB', 'FLD_TB.fld_id', '=', 'FLD_PO_TB.fld_id')
              		->join('SYS_territories', 'SYS_territories.terr_id', '=', 'FLD_PO_TB.del_to_terr_id')
                    ->join('SYS_tenants', 'SYS_tenants.tena_id', '=', 'FLD_PO_TB.to_org_id')
                    ->where('FLD_PO_TB.from_org_id', '=', $user_tenant_id)
                    ->where('FLD_PO_TB.po_status', '=', 'NOT_SENT')
                    ->where('FLD_PO_TB.po_release_status', 'OPEN')
                    ;
        if(isset($options['groupBy']) && $options['groupBy']){
            if($options['groupBy'] == 'flower_drive'){
                $orders = $orders->orderBy('FLD_TB.fld_name', 'ASC');
            }elseif($options['groupBy'] == 'supplier'){
                $orders = $orders->orderBy('SYS_tenants.tena_name', 'ASC');
            }elseif($options['groupBy'] == 'site'){
                $orders = $orders->orderBy('SYS_territories.terr_name', 'ASC');
            }elseif($options['groupBy'] == 'fld_type'){
                $orders = $orders->orderBy('FLD_TB.fld_type', 'ASC');
            }
        }

        if($options){
            if (!empty($options['advanceSearchFiltersSupplier'])) {
                $orders = $orders->where('SYS_tenants.tena_id', '=', $options['advanceSearchFiltersSupplier']);
            }
            if (!empty($options['advanceSearchFiltersPONumber'])) {
                $orders = $orders->where('FLD_PO_TB.fld_po_no', '=', $options['advanceSearchFiltersPONumber']);
            }
            if (!empty($options['advanceSearchFiltersFlName'])) {
                $orders = $orders->where('FLD_TB.fld_name', '=', $options['advanceSearchFiltersFlName']);
            }
            if (!empty($options['advanceSearchFiltersSites']) > 0) {
                $orders = $orders->whereIn('SYS_territories.terr_id', $options['advanceSearchFiltersSites']);
            }
            if (!empty($options['advanceSearchFiltersCompleteFromDate']) && !empty($options['advanceSearchFiltersCompleteToDate'])) {
                $orders = $orders->whereBetween('FLD_TB.complete_by_date', [$options['advanceSearchFiltersCompleteFromDate'], $options['advanceSearchFiltersCompleteToDate']]);
            }
            if (!empty($options['advanceSearchFiltersFldType'])) {
                $orders = $orders->where('FLD_TB.fld_type', $options['advanceSearchFiltersFldType']);
            }
            elseif (!empty($options['advanceSearchFiltersCompleteFromDate']) && empty($options['advanceSearchFiltersCompleteToDate'])) {
                $orders = $orders->whereDate('FLD_TB.complete_by_date', '>=', [$options['advanceSearchFiltersCompleteFromDate']]);
            }
            elseif (empty($options['advanceSearchFiltersCompleteFromDate']) && !empty($options['advanceSearchFiltersCompleteToDate'])) {
                $orders = $orders->whereDate('FLD_TB.complete_by_date', '<=', $options['advanceSearchFiltersCompleteToDate']);
            }
            if($options['sortBy']['column']){
                if($options['sortBy']['column'] == 'fld_po_no'){
                    $orders = $orders->orderBy('fld_po_id', $options['sortBy']['type']);
                }
                if($options['sortBy']['column'] == 'supplier'){
                    $orders = $orders->orderBy('tena_name', $options['sortBy']['type']);
                }
                if($options['sortBy']['column'] == 'flower_drive'){

                    $orders = $orders->orderBy('fld_name', $options['sortBy']['type']);
                }
                if ($options['sortBy']['column'] == 'site') {
                    $orders = $orders->orderBy('terr_name', $options['sortBy']['type']);
                }
                if ($options['sortBy']['column'] == 'cost') {
                    $orders = $orders->orderBy('order_total', $options['sortBy']['type']);
                }
                if ($options['sortBy']['column'] == 'orders') {
                    $orders = $orders->orderBy('number_of_orders', $options['sortBy']['type']);
                }
                if ($options['sortBy']['column'] == 'complete_by_date') {
                    $orders = $orders->orderBy('complete_by_date', $options['sortBy']['type']);
                }
                if ($options['sortBy']['column'] == 'status') {
                    $orders = $orders->orderBy('po_status', $options['sortBy']['type']);
                }
                if ($options['sortBy']['column'] == 'fld_type') {
                    $orders = $orders->orderByRaw(orderByWithNullOrEmptyLast(DB::raw('CASE WHEN FLD_TB.fld_type = "CAMPAIGN" THEN "CAMPAIGN" WHEN  FLD_TB.fld_type = "STORE" THEN "STORE" END'), $options['sortBy']['type']));
                }
            }
            if ($options['search']) {
                $searchTerm = $options['search'];
                $orders = $orders->where(function ($query) use ($searchTerm) {
                    $query
                        ->orWhere('FLD_PO_TB.fld_po_no', 'like', '%' . $searchTerm . '%')
                        ->orWhere('FLD_TB.complete_by_date', 'like', '%' . $searchTerm . '%')
                        ->orWhere('SYS_territories.terr_name', 'like', '%' . $searchTerm . '%')
                        ->orWhere('FLD_TB.fld_name', 'like', '%' . $searchTerm . '%')
                        ->orWhere('FLD_PO_TB.currency', 'like', '%' . $searchTerm . '%')
                        ->orWhere('SYS_tenants.tena_name', 'like', '%' . $searchTerm . '%')
                        ->orWhere('FLD_TB.fld_type', 'like', '%' . $searchTerm . '%');
                });
            }
            if (!empty($options['fd_list']) > 0 && !empty($options['site_list']) > 0) {
                $orders = $orders->whereIn('FLD_PO_TB.fld_id', $options['fd_list'])
                    ->whereIn('FLD_PO_TB.del_to_terr_id', $options['site_list']);
            }
        }

        if ($pluck != '') {
            $orders = $orders->pluck($pluck);
        } elseif (!empty($options['paginate'])) {
            $orders = $orders->paginate($options['paginate']);
        } else {
            if ($limit) {
                $orders = $orders->limit($limit)->get();
            } else {
                $orders = $orders->get();
            }
        }
        return $orders;
    }

    public function updatePurchaseOrderTable($fld_id)
    {
        $date = date('Y-m-d');
        // dd($date);
        $returnValue = DB::table('FLD_PO_TB')
            ->where('fld_po_id', '=', $fld_id)
            ->update([
                'po_status' => 'Ordered',
                'order_date' => $date,
            ]);
    }

    public function getInfoForEmails($optionsPara)
    {
        $user = getLoggedInUser();
        $user_tenant_id = $user->user_tenant_id;
        $resultSet = [];
        foreach ($optionsPara as $para) {
            $emailData = FlowerDrivePurchaseOrder::join('SYS_tenants', 'SYS_tenants.tena_id', '=', 'FLD_PO_TB.to_org_id')
                ->join('SYS_registrations', 'SYS_registrations.regi_id', '=', 'SYS_tenants.registration_id')
                ->where('FLD_PO_TB.from_org_id', '=', $user_tenant_id)
                ->whereIn('FLD_PO_TB.fld_po_no', $optionsPara)
                ->get(['FLD_PO_TB.from_terr_id', 'SYS_registrations.regi_org_name', 'SYS_tenants.tena_name', 'FLD_PO_TB.fld_po_no', 'FLD_PO_TB.fld_po_id', 'SYS_registrations.regi_business_email', 'SYS_registrations.regi_org_name']);
            $resultSet[] = $emailData;
        }
        return $resultSet;
    }

    public function _searchChunksGenerateOrder($purchaseOrder, $options)
    {
        $user = getLoggedInUser();
        if ($options['columns'] != null) {
            $selectedColumn = array_flip($options['columns']);
        }
        $options['search'] = wildCardCharacterReplace($options['search']);
    }

    public function getAllSitesForList($siteName)
    {
        $siteSearchData = [];
        $siteData = [];
        $user = getLoggedInUser();
        $user_tenant_id = $user->user_tenant_id;
        if($siteName){
            $allSitesList = Territory::select('terr_id', 'terr_name','region_id', 'address_1')
            ->where('terr_name', 'like', '%'.$siteName.'%')
            ->where('is_logical', '=', '0')
            ->orderBy('terr_name', 'ASC')
            // ->where('terr_tenant_id', '=', $user_tenant_id)
            ->with(['region' => function ($q) {
                $q->select('id', 'country_id', 'town_short_name', 'town_long_name')->with(['country' => function ($qe) {
                    $qe->select('id', 'country_name');
                }]);
            }])
            ->without('companyMainUser', 'users', 'tenant')
            ->get();
            $siteData['searchSiteName'] = ['Site_name' => $siteName];
            foreach ($allSitesList as $searchedAllSitesList) {
                $siteName = $searchedAllSitesList->terr_name;
                $address_1 = $searchedAllSitesList->address_1;
                $country = $searchedAllSitesList->region->country->country_name;
                $town = $searchedAllSitesList->region->town_short_name;
                $state = $searchedAllSitesList->region->town_long_name;
                $id = $searchedAllSitesList->terr_id;

                array_push($siteSearchData, [
                    'id' => $id,
                    'siteName' => decodeHtml($siteName),
                    'address_1' => decodeHtml($address_1),
                    'country' => decodeHtml($country),
                    'town' => decodeHtml($town),
                    'state' => decodeHtml($state),
                ]);
                $siteData['siteDetails'] = $siteSearchData;
            }
        }
        else{
            $allSitesList = DB::table('SYS_territories')
                ->select('terr_id', 'terr_name')
                ->where('terr_tenant_id', '=', $user_tenant_id)
                ->get();
            $siteData = $allSitesList;
        }
        return $siteData;
    }

    public function getAllSites($fldIds = [])
    {
        $user_tenant_id = getLoggedInUser()->user_tenant_id;
        $siteListQuery = FlowerDrivePurchaseOrder::select('from_org_id', 'from_terr_id', 'del_to_terr_id', 'created_by', 'fld_id')
            ->with(
                ['flowerDrive' => function ($q) {
                    $q->select('fld_id')->with(['flowerDriveSite' => function ($qe) {
                        $qe->select('fld_id', 'terr_id')->with(['territory' => function ($qee) {
                            $qee->select('terr_id', 'terr_name')->where('is_logical', '=', '0');
                        }]);
                    }]);
                }]
            )->where('from_org_id', $user_tenant_id);
        $flower_drives = [];
        foreach ($fldIds as $id) {
            $flower_drives[] = json_decode($id)->fld_id;
        }
        if (!empty($flower_drives)) {
            $siteListQuery = $siteListQuery->whereHas('flowerDrive', function ($q) use ($flower_drives) {
                $q->select('fld_id')->whereIn('fld_id', $flower_drives);
            })->get();
        } else {
            $siteListQuery = $siteListQuery->get();
        }
        $structFLdSitesArr = [];
        foreach ($siteListQuery as $siteData) {
            foreach ($siteData->flowerDrive->flowerDriveSite as $flowerDriveSite) {
                if (!isset($structFLdSitesArr[$flowerDriveSite->terr_id])) {
                    $structFLdSitesArr[$flowerDriveSite->terr_id] = [
                        'terr_id' => $flowerDriveSite->terr_id,
                        'terr_name' => $flowerDriveSite->territory->terr_name,
                    ];
                }
            }
        }

        return $structFLdSitesArr;
    }

    public function getFldList()
    {
        $user = getLoggedInUser();
        $user_tenant_id = $user->user_tenant_id;
        $pendingFLdList = FlowerDrivePurchaseOrder::select('fld_po_id', 'fld_id', 'from_terr_id','del_to_terr_id')
            ->with(['flowerDrive' => function ($q) {
                $q->select('fld_id', 'fld_name')->orderBy('fld_name', 'ASC');
            }])
            ->where('po_status', 'NOT_SENT')
            ->where('from_org_id', $user_tenant_id)
            ->get();

        $structPendingFLdDetailsArr = [];
        foreach ($pendingFLdList as $pendingFLd) {
            if (!isset($structPendingFLdDetailsArr[$pendingFLd->fld_id])) {
                $structPendingFLdDetailsArr[$pendingFLd->fld_id] = [
                    'fld_id' => $pendingFLd->fld_id,
                    'fld_name' => $pendingFLd->flowerDrive->fld_name,
                ];
            }
        }

        return $structPendingFLdDetailsArr;
    }

    public function getLiveSearchData($searchKey)
    {
        $orders = FlowerDrivePurchaseOrder::query()->select('fld_po_id', 'fld_id', 'fld_po_no', 'order_total', 'sup_by_date', 'po_status', 'from_terr_id')
            ->with(
                ['flowerDriveOrderDetails' => function ($c) {
                    $c->select('fld_id', 'fld_name');
                },
                    'trtryAndFldOder' => function ($c) {
                        $c->select('terr_id', 'terr_name');
                    }
                ]
            )->whereLike('fld_id', $searchKey)
            ->orWhereLike('order_total', $searchKey)
            ->get();
    }

    public function getAllOrdersCount($dataArray)
    {
        $user = getLoggedInUser();
        $user_tenant_id = $user->user_tenant_id;
        $orders = '';
        if ($dataArray) {
            if ($dataArray == 'flower_drive') { //DB::raw("count(assigned_tags.tag_id) as count")
                $orders = FlowerDrivePurchaseOrder::join('FLD_TB', 'FLD_TB.fld_id', '=', 'FLD_PO_TB.fld_id')
                    ->join('SYS_territories', 'SYS_territories.terr_id', '=', 'FLD_PO_TB.del_to_terr_id')
                    ->where('SYS_territories.terr_tenant_id', '=', $user_tenant_id)
                    ->where('FLD_PO_TB.po_status', '=', 'NOT_SENT')
                    ->select(DB::raw("count(FLD_PO_TB.fld_id) as total"));
            } elseif ($dataArray == 'site') {
                $orders = FlowerDrivePurchaseOrder::join('FLD_TB', 'FLD_TB.fld_id', '=', 'FLD_PO_TB.fld_id')
                    ->join('SYS_territories', 'SYS_territories.terr_id', '=', 'FLD_PO_TB.del_to_terr_id')
                    ->where('SYS_territories.terr_tenant_id', '=', $user_tenant_id)
                    ->where('FLD_PO_TB.po_status', '=', 'NOT_SENT')
                    ->select(DB::raw("count(SYS_territories.terr_id) as total"));

            } elseif ($dataArray == 'complete_by_date') {
                $orders = FlowerDrivePurchaseOrder::join('FLD_TB', 'FLD_TB.fld_id', '=', 'FLD_PO_TB.fld_id')
                    ->join('SYS_territories', 'SYS_territories.terr_id', '=', 'FLD_PO_TB.del_to_terr_id')
                    ->where('SYS_territories.terr_tenant_id', '=', $user_tenant_id)
                    ->where('FLD_PO_TB.po_status', '=', 'NOT_SENT')
                    ->select(DB::raw("count(FLD_TB.complete_by_date) as total"));
            }
            elseif ($dataArray == 'fld_type') {
                $orders = FlowerDrivePurchaseOrder::join('FLD_TB', 'FLD_TB.fld_id', '=', 'FLD_PO_TB.fld_id')
                    ->join('SYS_territories', 'SYS_territories.terr_id', '=', 'FLD_PO_TB.del_to_terr_id')
                    ->where('SYS_territories.terr_tenant_id', '=', $user_tenant_id)
                    ->where('FLD_PO_TB.po_status', '=', 'NOT_SENT')
                    ->select(DB::raw("count(FLD_TB.fld_type) as total"));
            }
        }
        return $orders;
    }

    public function extractUniqueGroupValues($groupByString)
    {
        $orders = '';
        if ($groupByString) {
            if ($groupByString == 'flower_drive') {
                $orders = FlowerDrivePurchaseOrder::query()->select('fld_id')
                    ->with(
                        ['flowerDriveOrderDetails' => function ($c) {
                            $c->select('fld_id', 'fld_name');
                        }
                        ]
                    )->get()
                    ->groupBy('fld_id');
            } elseif ($groupByString == 'site') {
                $orders = FlowerDrivePurchaseOrder::query()->select('fld_id')
                    ->with(
                        ['trtryAndFldOder' => function ($c) {
                            $c->select('terr_id', 'terr_name');
                        }
                        ]
                    )->get()
                    ->groupBy('trtryAndFldOder.terr_id');
            } elseif ($groupByString == 'complete_by_date') {
                $orders = FlowerDrivePurchaseOrder::query()->select('fld_id')
                    ->with(
                        ['flowerDriveOrderDetails' => function ($c) {
                            $c->select('fld_id', 'complete_by_date');
                        }
                        ]
                    )->get()
                    ->groupBy('flowerDriveOrderDetails.complete_by_date');

            }
        }
        return $orders;
    }

    public function groupResultOrders($items, $isRelationShipGroupBy = false, $groupByColumnName, $groupCounts = [])
    {
        $responseDataSet = [];
        $groupColumnName = '';
        if ($groupByColumnName) {
            $groups = [];
            foreach ($items as $key => $item) {
                if ($groupByColumnName == 'flower_drive') {
                    $groupColumnName = $item->fld_name;
                } elseif ($groupByColumnName == 'site') {
                    $groupColumnName = $item->terr_name;
                } elseif ($groupByColumnName == 'complete_by_date') {
                    $groupColumnName = $item->complete_by_date;
                } elseif ($groupByColumnName == 'supplier') {
                    $groupColumnName = $item->tena_name;
                } elseif ($groupByColumnName == 'fld_type') {
                    $groupColumnName = $item->fld_type;
                } else {
                    $groupColumnName = 'No Group';
                }

                if (!array_key_exists(str_replace(" ", "_", $groupColumnName), $groups)) {
                    $groups[str_replace(" ", "_", $groupColumnName)] = [];
                    $groups = $this->_pushItemToGroupArray(str_replace(" ", "_", $groupColumnName), $item, $groups);
                } else {
                    $groups = $this->_pushItemToGroupArray(str_replace(" ", "_", $groupColumnName), $item, $groups);
                }
            }
            if ($groups) {
                foreach ($groups as $key => $group) {
                    if ($groupCounts && array_key_exists($key, $groupCounts)) {
                        $responseDataSet[(str_replace("_", " ", $keyVal)) . " (" . $groupCounts[$key] . ")"] = $group;
                    } else {
                        $responseDataSet[(str_replace("_", " ", decodeHtml($key))) . " (" . count($group) . ")"] = $group;
                    }
                }
            }
        }

        $paginationDetails = $items->toArray();
        $paginator = [];
        $paginator['data'] = $responseDataSet;
        $paginator['first_page_url'] = $paginationDetails['first_page_url'];
        $paginator['from'] = $paginationDetails['from'];
        $paginator['last_page'] = $paginationDetails['last_page'];
        $paginator['last_page_url'] = $paginationDetails['last_page_url'];
        $paginator['next_page_url'] = $paginationDetails['next_page_url'];
        $paginator['path'] = $paginationDetails['path'];
        $paginator['per_page'] = $paginationDetails['per_page'];
        $paginator['prev_page_url'] = $paginationDetails['prev_page_url'];
        $paginator['to'] = $paginationDetails['to'];
        $paginator['total'] = $paginationDetails['total'];
        $paginator['current_page'] = $paginationDetails['current_page'];
        return $paginator;
    }

    private function _pushItemToGroupArray($groupKey, $item, $resultSet)
    {
        array_push($resultSet[$groupKey], $item);
        return $resultSet;
    }

    /**
     * Get purchase orders
     *
     * @param FlowerDrivePurchaseOrder $fldPOId
     * @param array $params
     *
     * @return array
     */
    public function getFldPODetails($fldPOId = null, $params = [], $isSupplier = false)
    {
        $userTenantId = getLoggedInUser()->user_tenant_id;
        $poDetails = FlowerDrivePurchaseOrder::select('fld_po_id', 'fld_po_no', 'order_total', 'po_status', 'fld_id', 'sup_by_date', 'to_org_id', 'from_org_id', 'from_terr_id', 'order_date', 'released_date', 'currency', 'is_delivery_placement', 'del_to_terr_id', 'as_an_agent', 'del_to_terr_id');
        if ($isSupplier) {
            $poDetails = $poDetails->where('to_org_id', $userTenantId);
        } else {
            $poDetails = $poDetails->where('from_org_id', $userTenantId);
        }
        if ($fldPOId) $poDetails = $poDetails->where('fld_po_id', $fldPOId);
        if (!empty($params)) $poDetails = $poDetails->whereIn('fld_po_id', $params);

        $poDetails = $poDetails->with(['flowerDrivePurchaseOrderItems' => function ($q) {
            $q->select('fld_po_id', 'po_line_id', 'item_id', 'qty_ordered', 'item_qty_received', 'item_qty_returned', 'qty_outstanding', 'unit_price', 'extended_cost', 'is_substitute', 'subsitute_parent', 'currency', 'is_delivery_placement', 'tot_qty_received', 'tot_qty_returned')
                ->where('is_substitute', 0)
                ->with(['item' => function ($q) {
                    $q->select('item_id', 'item_name', 'item_type', 'supplier_item_code')
                        ->with([
                            'itemPricingDetail' => function ($q) {
                                $q->select('item_id', 'is_delivery', 'is_delivery_with_placement', 'cost_type_id');
                            }
                        ])->with([
                            'itemTypes' => function ($q) {
                                $q->select('item_type', 'item_type_desc');
                            }
                        ]);
                }])->with(['substitutes' => function ($q) {
                    $q->select('fld_po_id', 'po_line_id', 'item_id', 'qty_ordered', 'item_qty_received', 'item_qty_returned', 'qty_outstanding', 'unit_price', 'extended_cost', 'is_substitute', 'subsitute_parent', 'is_delivery_placement', 'tot_qty_received', 'tot_qty_returned')
                        ->with(['item' => function ($q) {
                            $q->select('item_id', 'item_name', 'item_type');
                        }]);
                }])->with(['consumerOrderItems' => function ($q) {
                    $q->select(DB::raw("DISTINCT (consumer_order_id)"), 'fld_po_line_id', 'consumer_order_item_id')
                        ->with(['consumerOrderItemLocations' => function ($q) {
                            $q->select('consumer_order_item_id', 'consumer_order_loc_id', 'location_id' );
                        }]);
                }]);
        }])->with(['consumerOrderItems' => function ($q) {
            $q->with(['consumerOrderItemLocations' => function ($q) {
                $q->with(['person', 'location' => function ($q) {
                    $q->with(['LocationType', 'siteArea', 'sectionOrArea']);
                }]);
            }]);
        }])->with(['flowerDrive' => function ($q) {
            $q->select('fld_id', 'fld_name', 'send_srv_shdl', 'complete_by_date', 'supply_by_date', 'fld_type');
        }])->with(['purchaseOrderReceipts' => function ($q) {
            $q->select('po_recipt_id', 'po_recipt_no', 'recipt_date')
                ->with(['purchaseOrderReceiptLines' => function ($q) {
                    $q->select('po_recipt_line_id', 'po_recipt_id', 'po_line_id', 'fld_po_id', 'item_id', 'type', 'item_receive_qty', 'item_return_qty', 'currency', 'unit_price', 'extended_cost');
                }]);
        }])->with(['supplier' => function ($q) {
            $q->select('tena_id', 'tena_name', 'registration_id')
                ->without('oxConfiguration')
                ->with(['registration' => function ($q) {
                    $q->select('regi_id', 'regi_address_1', 'regi_address_2', 'regi_business_email', 'regi_surname', 'regi_other_name')
                        ->without('billingDetails', 'accountContact');
                }, 'user' => function ($q) {
                    $q->select('user_tenant_id', 'business_email');
                }]);
        }])->with(['deliveryTo' => function ($q) {
            $q->select('terr_id', 'terr_name', 'address_1', 'address_2', 'country', 'tax_number', 'contact_surname', 'contact_other_name', 'contact_phone', 'contact_email')
                ->without('companyMainUser', 'territoryPermission', 'users');
        }])->with(['territory' => function ($q) {
            $q->select('terr_id', 'terr_name', 'address_1', 'address_2', 'country', 'tax_number', 'contact_surname', 'contact_other_name', 'contact_phone', 'contact_email')
                ->without('companyMainUser', 'territoryPermission', 'users');
        }])->with(['purchaseOrderReceipts' => function ($q) {
            $q->select('fld_po_id', 'po_recipt_id', 'allocated_status');
        }]);


        if (empty($fldPOId)) {
            $poDetails = $poDetails->get();
        } else {
            $poDetails = $poDetails->first();
        }

        return $poDetails;
    }

    public function getPurchaseOrderRecepts($fldPOId)
    {
        $poReceipts = FlowerDrivePurchaseOrderReceipt::select('po_recipt_id', 'po_recipt_no', 'recipt_date', 'fld_po_id')
            ->where('fld_po_id', $fldPOId)
            ->with(['purchaseOrderReceiptLines' => function ($q) {
                $q->select('po_recipt_line_id', 'po_recipt_id', 'po_line_id', 'fld_po_id', 'item_id', 'type', 'item_receive_qty', 'item_return_qty', 'currency', 'unit_price', 'extended_cost')
                    ->with(['item' => function ($q) {
                        $q->select('item_id', 'item_name', 'item_type');
                    }])->with(['purchaseOrderLine' => function ($q) {
                        $q->select('po_line_id', 'qty_ordered', 'item_qty_received', 'item_qty_returned', 'qty_outstanding', 'is_substitute', 'subsitute_parent', 'qty_ordered');
                    }]);
            }])
            ->get();
        return $poReceipts;
    }

    public function createPoOrders($order)
    {
        return FlowerDrivePurchaseOrder::create($order);
    }

    public function createPoLine($item)
    {
        return FlowerDrivePurchaseOrderLine::create($item);
    }

    public function getAllFlowerDrives($isCampaignOnly = false)
    {
        $user = getLoggedInUser();
        $org_id = $user->user_tenant_id;
        $query = FlowerDrive::select('fld_id', 'fld_name', 'order_by_date', 'cancel_by_date', 'timezone')
            ->where('fld_status', 'PUBLISHED')
            ->where('org_id', $org_id);
        if ($isCampaignOnly) {
            $query->where('fld_type', FLD_TYPE_CAMPAIGN);
        }
        $flowerDrives = $query->selectRaw('CAST(REGEXP_SUBSTR(fld_name,"[0-9]+")AS UNSIGNED) AS `flower_drive_name`')
            ->orderByRaw("REGEXP_SUBSTR(fld_name,'[a-z|A-Z]+') " . 'ASC')
            ->orderBy("flower_drive_name", 'ASC')
            ->get();

        $flowerDriveList = [];
        foreach ($flowerDrives as $fld) {
            $maxDate = $fld->order_by_date;
            $currentDate = $this->_getCurrentDateByTimeZone($fld->timezone);
            if ($fld->order_by_date < $fld->cancel_by_date) {
                $maxDate = $fld->cancel_by_date;
            }
            if ($maxDate < $currentDate) {
                array_push($flowerDriveList, $fld);
            }
        }
        return $flowerDriveList;
    }

    public function getAllAvailableSites($selectedFds)
    {
        $sites = [];
        if (!empty($selectedFds)) {
            $terr_list = FlowerDriveSite::select('terr_id')->whereIn('fld_id', $selectedFds)->groupBy('terr_id')->get();
            $sites = DB::table('SYS_territories')->select('terr_id', 'terr_parent_id', 'terr_name')->whereIn('terr_id', $terr_list)->where('deleted_at', null)->orderBy('terr_name', 'ASC')->where('is_logical',0)->get();
        }
        return $sites;
    }

    public function generateOrderList($option, $fd_list, $site_list)
    {
        $groupBy = $option['groupBy'];
        if (!is_array($site_list) || is_null($site_list)) {
            $site_list = [];
        }
        if (!is_array($fd_list) || is_null($fd_list)) {
            $fd_list = [];
        }

        $order_list = $this->getOrderListForPurchaseOrders($fd_list, $site_list);

        return $order_list;
    }

    public function getOrderListForPurchaseOrders($fldList = [], $siteList = [], $consumerOrderId = null)
    {
        $query = ConsumerOrder::select('consumer_order_id','grand_total', 'consumer_order_id', 'fld_id', 'terr_id', 'grand_total');

        if ($consumerOrderId) {
            $query = $query->where('consumer_order_id', $consumerOrderId);
        } else {
            $query = $query->whereIn('fld_id', $fldList)
                ->whereIn('terr_id', $siteList);
        }

        $query = $query->where('order_status', 'ODRD');

        $orders = $query->with([
                //get order items for each consumer order
                'orderItems' => function ($q) {
                    $q->select('item_id', 'consumer_order_item_id', 'consumer_order_id', 'quantity', 'item_price', 'is_del_placement', 'fld_po_id')
                        ->whereNull('fld_po_id')
                        ->whereNull('fld_po_line_id')
                        ->with(['item' => function ($qe) {
                            //get item and supplier details for each consumer order item
                            $qe->select('item_id', 'org_id', 'item_name', 'item_type')->with(['supplier' => function ($qee) {
                                //get acoount details for each supplier
                                $qee->select('tena_id', 'tena_name', 'registration_id')->with(['registration' => function ($qeee) {
                                    $qeee->select('regi_id', 'regi_address_1', 'regi_address_2', 'regi_region_id')->with(['billingDetails' => function ($q) {
                                        $q->select('registration_id', 'currency')->with(['currencyDetails' => function ($q) {
                                            $q->select('id', 'currency_name');
                                        }]);
                                    }, 'region' => function ($q) {
                                        $q->select('id', 'country_id', 'country_state_id', 'town_short_name', 'postal_id')
                                            ->with(['country' => function ($q) {
                                                $q->select('id', 'country_name');
                                            }, 'countryState' => function ($q) {
                                                $q->select('id', 'state_name');
                                            }, 'postalCode' => function ($q) {
                                                $q->select('id', 'postal_code');
                                            }]);
                                    },'accountContact'=> function($q){
                                        $q-> select('registration_id','account_email');
                                    }]);
                                },'parentTerritory'=>function($q){
                                    $q->select('terr_tenant_id','contact_email');
                                }]);
                            }]);
                            //get consumer order Item location
                        }
                            //get location details for each item
                            , 'orderItemLocData' => function ($q) {
                                $q->select('consumer_order_item_id', 'location_id');
                            }
                            , 'orderItemLocDetails' => function ($q) {
                                $q->select('consumer_order_item_id', 'location_id' );
                            }
                        ]);
                },
                //get flower drive details for each consumer order
                'flowerDrive' => function ($q) {
                    $q->select('fld_id', 'org_id', 'fld_name', 'supply_by_date', 'created_by', 'start_date', 'order_by_date', 'cancel_by_date', 'supply_by_date', 'complete_by_date', 'as_an_agent');
                },
                //get site details for each consumer order
                'territories' => function ($q) {
                    $q->select('terr_id', 'terr_name', 'address_1', 'address_2', 'country', 'tax_number', 'region_id', 'terr_tenant_id', 'comp_id', 'site_id')->with(['territoryCountry' => function ($q) {
                        $q->select('id', 'country_name');
                    },
                        //get site state,country
                        'region' => function ($q) {
                            $q->select('id', 'postal_id', 'country_state_id','town_short_name')->with(['postalCode' => function ($q) {
                                $q->select('id', 'postal_code');
                            }, 'countryState' => function ($q) {
                                $q->select('id', 'state_name');
                            }]);
                        },
                        //get tenants country name , postal code  and state name
                        'tenant' => function ($q) {
                            $q->select('tena_id', 'tena_name', 'registration_id', 'tena_type')->with(['registration' => function ($qeee) {
                                $qeee->select('regi_id', 'regi_address_1', 'regi_address_2', 'regi_region_id', 'regi_org_name')->with(['region' => function ($q) {
                                    $q->select('id', 'country_id', 'country_state_id', 'postal_id','town_short_name')->with(['country' => function ($q) {
                                        $q->select('id', 'country_name');
                                    }, 'countryState' => function ($q) {
                                        $q->select('id', 'state_name');
                                    }, 'postalCode' => function ($q) {
                                        $q->select('id', 'postal_code');
                                    }
                                    ]);
                                }]);
                            },'parentTerritory'=>function($q){
                                $q->select('terr_tenant_id','contact_email');
                            }]);
                        }]);
                }])
            //get only new consumer order items to generate
            ->whereHas('orderItems', function ($query) {
                $query->where('is_pkg', 0)
                    ->whereNull('fld_po_id')
                    ->whereNull('fld_po_line_id');
            })
            ->get();

        $orderList = $this->orderList($orders);
        return $orderList;
    }

    public function cancelPurchaseOrder($fldPOId)
    {
        $data = [];
        $pOReciptData=FlowerDrivePurchaseOrderReceipt::select('po_recipt_id')
        ->where('fld_po_id', $fldPOId)->get();

        if($pOReciptData->isEmpty())
        {
            $updatedConsumerOrderItemData = ConsumerOrderItem::where('fld_po_id', $fldPOId)
            ->where('is_pkg', 0)
                ->update(['fld_po_id' => null,'fld_po_line_id' => null, 'updated_by' => getLoggedInUser()->id]);

            $updatedPurchaseOrderData = FlowerDrivePurchaseOrder::where('fld_po_id', $fldPOId)
                    ->update(['po_status' => 'CANCELLED','cancel_date' => date('Y-m-d h:i:s'),'updated_by' => getLoggedInUser()->id]);
            $data['status']=true;
            $data['mgs']="Done";
        }
        else{
            $data['status']=false;
            $data['mgs']="Something went wrong, Please try again.";
        }
        return ($data);
    }

    private function orderList($consumerOrders)
    {
        $order_list = [];
        $purchase_order_list = [];
        //Loop consumer orders to generate purchase orders[]
        foreach ($consumerOrders as $consumerOrder) {
            $items = [];
            $suppliers = [];
            $grand_total = 0;
            $po = [];
            $po['orders']=[];
            $po['fld_name'] = $consumerOrder->flowerDrive->fld_name;
            $po['fld_id'] = $consumerOrder->flowerDrive->fld_id;
            $po['org_id'] = $consumerOrder->flowerDrive->org_id;
            $po['terr_id'] = $consumerOrder->territories->terr_id;
            $po['del_to_org_id'] = $consumerOrder->flowerDrive->org_id;
            $po['del_to_terr_id'] = $consumerOrder->territories->terr_id;
            $po['cancel_by_date'] = $consumerOrder->flowerDrive->cancel_by_date;
            $po['site_name'] = $consumerOrder['territories']->terr_name;
            $po['site_id'] = $consumerOrder['territories']->terr_id;
            $po['supply_by_date'] = $consumerOrder->flowerDrive->supply_by_date;
            $po['as_an_agent'] = $consumerOrder->flowerDrive->as_an_agent;
            $po['site_details'] = $consumerOrder['territories'];
            $po['purchase_order'] = [];
            $po['is_delivery_placement'] = 0;
            $po['qty_outstanding_list'] = [];
//            $po['cost'] = $consumerOrder->grand_total;

            $consumer_order_items = $consumerOrder->orderItems;
            if ($consumer_order_items->isNotEmpty()) {
                //loop consumer order items to create item and supplier list for each PO
                foreach ($consumer_order_items as $order_item) {
//                    dd($order_item->orderItemLocDetails);
                    if (!empty($order_item->item) && !empty($order_item->item->supplier) && !empty($order_item->orderItemLocDetails)) {
                        $item = [
                            "item_id" => $order_item->item->item_id,
                            "org_id" => $order_item->item->org_id,
                            "item_type" => $order_item->item->item_type,
                            "quantity" => $order_item->quantity,
                            "item_properties" => [
                                "item_id" => $order_item->item->item_id,
                                "item_type" => $order_item->item->item_type,
                                "supp_id" => $order_item->item->supplier->tena_id,
                                "is_del_placement" => $order_item->is_del_placement,
                                "org_id" => $consumerOrder->flowerDrive->org_id
                            ],
                            "item_name" => $order_item->item->item_name,
                            "consumer_order_item_id" => $order_item->consumer_order_item_id,
                            "is_delivery_placement" => $order_item->is_del_placement,
                            "location_count" => 0,
                            "location_list" => $this->_createLocationList($order_item->orderItemLocDetails),
                            "item_price" => 0,
                            "warnings" => [],
                        ];
                        $qty_outstanding = [
                            "consumer_order_item_id" => $order_item->consumer_order_item_id,
                            "qty_outstanding" => $order_item->quantity
                        ];
                        array_push($items, $item);
                        //remove duplicate suppliers
                        if (empty($suppliers) || !in_array($order_item->item->supplier, $suppliers)) {
                            array_push($suppliers, $order_item->item->supplier);
                        }
                        array_push($po['qty_outstanding_list'], $qty_outstanding);
                    }
                }

//                $cost = 0;
                $count = 0;
                foreach ($items as $item) {
//                    $cost += $item['item_price'] * $item['quantity'];
                    $count += $item['quantity'];
                }
//                $po['cost'] = $cost;
                $po['order_count'] = $count;
                $po['items'] = $items;
                //Loop suppliers for generate seperate po for each supplier
                foreach ($suppliers as $supplier) {

                    $po['supplier'] = $supplier->tena_name;
                    $po['supplier_id'] = $supplier->tena_id;
                    $po['supplier_details'] = $supplier->registration;
                    $po['supplier_email'] = $supplier->parentTerritory->contact_email;
                    $count = 0;
                    $po_items = [];
                    //Loop items to create item list belong to each supplier
                    foreach ($items as $item) {
                        if ($item['org_id'] == $supplier->tena_id) {
                            array_push($po_items, $item);
                        }
                    }
                    //add item list belong to po
                    $po['items'] = $po_items;
                    array_push($po['orders'] , $consumerOrder->consumer_order_id );
                    //add each po generate to purchase order list
                    array_push($order_list, $po);
                }
            }
        }

        $merged_data = array();
        // Loop order details for merge items duplicate purchase order
        foreach ($order_list as $order) {
            if (isset($order["fld_name"]) && isset($order["site_name"]) && isset($order["supplier"])) {
                $key = $order["fld_name"] . "-" . $order["site_name"] . "-" . $order["supplier"];
                if (array_key_exists($key, $merged_data)) {
                    foreach ($order['items'] as $item) {
                        array_push($merged_data[$key]['items'], $item);
                    }
                    $merged_data[$key]["order_count"] += $order["order_count"];
                    $merged_data[$key]["orders"] = array_merge($merged_data[$key]["orders"],$order["orders"]);
//                    $merged_data[$key]["cost"] += $order["cost"];
                    foreach ($order['qty_outstanding_list'] as $qty) {
                        array_push($merged_data[$key]["qty_outstanding_list"], $qty);
                    }

                } else {
                    $merged_data[$key] = $order;
                }
            }
        }
        //Loop each purchase orders for calculate item price, location count
        foreach ($merged_data as $key => $data) {
            $merged_items = array();
            $merged_item_locations = [];
            $count = 0;
            foreach ($data['items'] as $item) {
                $item_key = $item['item_id'];

                if (array_key_exists($item_key, $merged_items)) {
                    $merged_items[$item_key]["quantity"] += $item["quantity"];
                    $merged_items[$item_key]["location_list"] = array_merge($merged_items[$item_key]["location_list"], $item["location_list"]);
                }
                else {
                    $merged_items[$item_key] = $item;
                }

                // add duplicated item ids to merge items data array
                if (isset($merged_items[$item_key]['consumer_order_item_ids'])) {
                    $existingArr = $merged_items[$item_key]['consumer_order_item_ids'];
                    array_push($existingArr, $item['consumer_order_item_id']);
                    $merged_items[$item_key]['consumer_order_item_ids'] = $existingArr;
                } else {
                    $merged_items[$item_key]['consumer_order_item_ids'] = [
                        $item['consumer_order_item_id']
                    ];
                }


            }
            //Iterate item list to generate price and warnings
            foreach ($merged_items as $item) {
                $item_locations= [];
                $item_key = $item['item_id'];
                $merged_items[$item_key]["item_price"] = $this->getItemPrice($merged_items[$item_key]["item_properties"]["item_id"], $merged_items[$item_key]["item_properties"]["item_type"], $merged_items[$item_key]["item_properties"]["supp_id"], $merged_items[$item_key]["quantity"], $merged_items[$item_key]["item_properties"]["is_del_placement"], $merged_items[$item_key]["item_properties"]["org_id"]);
                $merged_items[$item_key]["warnings"] = $this->getWarnings($merged_items[$item_key]["item_properties"]["item_id"], $merged_items[$item_key]["quantity"], $merged_items[$item_key]["item_properties"]["supp_id"]);
                $merged_items[$item_key]["location_count"] = count(array_unique($merged_items[$item_key]["location_list"]));
            }

            $merged_data[$key]['items'] = array_values($merged_items);

        }

        //generate cost for purchase order items
        $merged_data = array_values($merged_data);
        $purchase_order_list = $merged_data;
        return $purchase_order_list;

    }

    private function _createLocationList($locationList)
    {
        $locations = [];
        if (!empty($locationList)) {
            foreach ($locationList as $location) {
                $key = $location['location_id'];
                if (!array_key_exists($key, $locations)) {
                    $locations[$key] = $location['location_id'];
                }
            }

            return $locations;
        } else {
            return $locations;
        }
    }

    public function getWarnings($item_id, $quantity, $sup_id)
    {
        $warning = [];
        if (!empty($item_id) && !empty($quantity) && !empty($sup_id)) {

            $item = ItemMaster::select('item_id', 'org_id', 'item_name')
                ->where('item_id', $item_id)
                ->where('org_id', $sup_id)
                ->with(['itemPricingDetail' => function ($q) {
                    $q->select('item_id', 'min_qty', 'cost_type_id');
                }, 'itemPricingPrice' => function ($qu) {
                    $qu->select('item_id', 'from_unit', 'to_unit');
                }, 'supplier' => function ($q) {
                    $q->select('tena_id', 'tena_name');
                }])->first();
            if (!empty($item->toArray())) {
                $pricingPrice = $item->itemPricingPrice;
                if ($item->itemPricingDetail->cost_type_id == 'QUT_PRCE') {
                    if ($item->itemPricingDetail->min_qty > $quantity) {
//                        $warning = 'Order quantity is less than minimum quantity for ' . $item->item_name;
                        $warning["message"] = "Order quantity is less than minimum quantity for  ";
                        $warning["item_name"] = $item->item_name;
                        $warning["min_qty"] = $item->itemPricingDetail->min_qty;
                        $warning["order_quantity"] = $quantity;
                        $warning["supplier"] = $item->supplier->tena_name;


                    }
                } elseif ($item->itemPricingDetail->cost_type_id == 'TIED') {
                    if (!empty($pricingPrice)) {

                        if ($pricingPrice[0]->from_unit > $quantity) {
//                            $warning = 'Order quantity is less than minimum quantity for  ' . $item->item_name;
                            $warning["message"] = "Order quantity is less than minimum quantity for   ";
                            $warning["item_name"] = $item->item_name;
                            $warning["min_qty"] = $item->itemPricingDetail->min_qty;
                            $warning["order_quantity"] = $quantity;
                            $warning["supplier"] = $item->supplier->tena_name;

                        }
//                        elseif ($pricingPrice[count($pricingPrice) - 1]->to_unit < $quantity) {
////                            $warning = 'Order quantity is higher than minimum quantity for  ' . $item->item_name;
//                            $warning["message"] = "Order quantity is higher than minimum quantity for   ";
//                            $warning["item_name"] = $item->item_name;
//                            $warning["min_qty"] = $item->itemPricingDetail->min_qty;
//                            $warning["order_quantity"] = $quantity;
//                            $warning["supplier"] = $item->supplier->tena_name;
//
//                        }
                    }
                }
            }
            return $warning;
        }

        return false;
    }

    public function priceGenerator($is_delivery_placement, $discount_rate, $rrp_deliver_placement, $rrp_delivery)
    {
        $price = 0;
        if ($is_delivery_placement === 1) {
            if (!empty($discount_rate)) {
                $price = $rrp_deliver_placement - $rrp_deliver_placement * ($discount_rate->discount_rate / 100);
            } else {
                $price = $rrp_deliver_placement;
            }
        } else {
            if (!empty($discount_rate)) {
                $price = $rrp_delivery - $rrp_delivery * ($discount_rate->discount_rate / 100);
            } else {
                $price = $rrp_delivery;
            }
        }
        return $price;
    }

    public function getItemPrice($item_id, $item_type, $sup_id, $quantity, $is_delivery_placement, $org_id)
    {
        if (!empty($item_id) && !empty($sup_id) && !empty($quantity)) {

            $price = 0;
            if ($quantity > 0) {
                $item = ItemMaster::select('item_id', 'item_type', 'org_id', 'item_name','is_internal')
                    ->where('item_id', $item_id)
                    ->where('org_id', $sup_id)
                    ->with(['itemPricingDetail' => function ($q) {
                        $q->select('item_id', 'delivery_only_price', 'delivery_with_placement_price', 'min_qty', 'cost_type_id');
                    },
                        'supplierPriceList' => function ($q) {
                            $q->select('sup_org_id', 'disc_option', 'pl_id')->where('status', 1);
                        }
                        , 'itemPricingPrice' => function ($qu) {
                            $qu->select('item_id', 'item_price_id', 'from_unit', 'to_unit', 'rrp_delivery_per_unit', 'rrp_delivery_placement_per_unit');
                        }])->first();
                //if have Price list
                if (!empty($item) && !empty($item->supplierPriceList) && !empty($item->itemPricingDetail) && $org_id != $item->org_id) {

                    $discOption = $item->supplierPriceList->disc_option;
                    $plId = $item->supplierPriceList->pl_id;
                    $rrp_delivery = $item->itemPricingDetail->delivery_only_price;
                    $rrp_deliver_placement = $item->itemPricingDetail->delivery_with_placement_price;
                    $pricingPrice = $item->itemPricingPrice;
                    $pricingPriceData = [];
                    $discountRate = 0;
                    if ($item->itemPricingDetail->cost_type_id == 'FXD') {
                        if ($discOption == 'ALL') {
                            $discountRate = PriceListOptionAll::select('discount_rate')->where('pl_id', $plId)->first();
                            $price = $this->priceGenerator($is_delivery_placement, $discountRate, $rrp_deliver_placement, $rrp_delivery);
                        }
                        if ($discOption == 'TYPE') {
                            $discountRate = PriceListOptionItemType::select('discount_rate')->where('pl_id', $plId)->where('item_type', $item_type)->first();
                            $price = $this->priceGenerator($is_delivery_placement, $discountRate, $rrp_deliver_placement, $rrp_delivery);
                        }
                        if ($discOption == 'ITEM') {
                            $discountRate = PriceListOptionItem::select('discount_rate')->where('pl_id', $plId)->first();
                            $price = $this->priceGenerator($is_delivery_placement, $discountRate, $rrp_deliver_placement, $rrp_delivery);
                        }
                    } elseif ($item->itemPricingDetail->cost_type_id == 'QUT_PRCE') {
                        if ($discOption == 'ALL') {
                            $discountRate = PriceListOptionAll::select('discount_rate')->where('pl_id', $plId)->first();
                            $price = $this->priceGenerator($is_delivery_placement, $discountRate, $rrp_deliver_placement, $rrp_delivery);
                        }
                        if ($discOption == 'TYPE') {
                            $discountRate = PriceListOptionItemType::select('discount_rate')->where('pl_id', $plId)->where('item_type', $item_type)->first();
                            $price = $this->priceGenerator($is_delivery_placement, $discountRate, $rrp_deliver_placement, $rrp_delivery);
                        }
                        if ($discOption == 'ITEM') {
                            $discountRate = PriceListOptionItem::select('discount_rate')->where('pl_id', $plId)->where('item_id', $item_id)->first();
                            $price = $this->priceGenerator($is_delivery_placement, $discountRate, $rrp_deliver_placement, $rrp_delivery);
                        }
                    } elseif ($item->itemPricingDetail->cost_type_id == 'TIED') {
                        if (!empty($pricingPrice)) {
                            foreach ($pricingPrice as $price) {
                                if ($price->from_unit <= $quantity && $price->to_unit >= $quantity) {
                                    $pricingPriceData = $price;
                                    break;
                                }
                            }

                            if (empty($pricingPriceData)) {
                                if($pricingPrice[0]['from_unit'] > $quantity){
                                $pricingPriceData = $pricingPrice[0];
                            }
                            elseif($pricingPrice[count($pricingPrice)-1]['to_unit'] < $quantity){
                                $pricingPriceData = $pricingPrice[count($pricingPrice)-1];
                            }
                            }
                        }
                        if ($discOption == 'ALL') {
                            $discountRate = PriceListOptionAll::select('discount_rate')->where('pl_id', $plId)->first();
                            if (isset($pricingPriceData)) {
                                if ($is_delivery_placement === 1) {
                                    if (!empty($discountRate)) {
                                        $price = $pricingPriceData->rrp_delivery_placement_per_unit - $pricingPriceData->rrp_delivery_placement_per_unit * ($discountRate->discount_rate / 100);
                                    } else {
                                        $price = $rrp_deliver_placement;
                                    }
                                } else {
                                    if (!empty($discountRate)) {
                                        $price = $pricingPriceData->rrp_delivery_per_unit - $pricingPriceData->rrp_delivery_per_unit * ($discountRate->discount_rate / 100);
                                    } else {
                                        $price = $pricingPriceData->rrp_delivery_per_unit;
                                    }
                                }
                            }
                        }
                        if ($discOption == 'TYPE') {
                            $discountRate = PriceListOptionItemType::select('discount_rate')->where('pl_id', $plId)->where('item_type', $item_type)->first();
                            if (!empty($pricingPriceData)) {
                                if ($is_delivery_placement === 1) {
                                    if (!empty($discountRate)) {
                                        $price = $pricingPriceData->rrp_delivery_placement_per_unit - $pricingPriceData->rrp_delivery_placement_per_unit * ($discountRate->discount_rate / 100);
                                    } else {
                                        $price = $rrp_deliver_placement;
                                    }
                                } else {
                                    if (!empty($discountRate)) {
                                        $price = $pricingPriceData->rrp_delivery_per_unit - $pricingPriceData->rrp_delivery_per_unit * ($discountRate->discount_rate / 100);
                                    } else {
                                        $price = $pricingPriceData->rrp_delivery_per_unit;
                                    }
                                }
                            }
                        }
                        if ($discOption == 'ITEM') {
                            $discountRate = PriceListOptionItem::select('discount_rate')->where('pl_id', $plId)->where('item_id', $item_id)->first();
                            if (isset($pricingPriceData)) {
                                if ($is_delivery_placement === 1) {
                                    if (!empty($discountRate)) {
                                        $price = $pricingPriceData->rrp_delivery_placement_per_unit - $pricingPriceData->rrp_delivery_placement_per_unit * ($discountRate->discount_rate / 100);
                                    } else {
                                        $price = $rrp_deliver_placement;
                                    }
                                } else {
                                    if (!empty($discountRate)) {
                                        $price = $pricingPriceData->rrp_delivery_per_unit - $pricingPriceData->rrp_delivery_per_unit * ($discountRate->discount_rate / 100);
                                    } else {
                                        $price = $pricingPriceData->rrp_delivery_per_unit;
                                    }
                                }
                            }
                        }
                    }
                }
                //If not have price list
                elseif(!empty($item) && !empty($item->itemPricingDetail)){

                    $rrp_delivery = $item->itemPricingDetail->delivery_only_price;
                    $rrp_deliver_placement = $item->itemPricingDetail->delivery_with_placement_price;
                    $pricingPrice = $item->itemPricingPrice;
                    if ($item->itemPricingDetail->cost_type_id == 'QUT_PRCE') {
                        if ($is_delivery_placement === 1) {
                            $price = $rrp_deliver_placement;
                        } else {
                            $price = $rrp_delivery;
                        }
                    } elseif ($item->itemPricingDetail->cost_type_id == 'TIED') {
                        if (!empty( $pricingPrice)) {
                            foreach ( $pricingPrice as $price) {
                                if ($price->from_unit <= $quantity && $price->to_unit >= $quantity) {
                                    $pricingPriceData = $price;
                                    break;
                                }
                            }
                            if (empty($pricingPriceData)) {
                                if($pricingPrice[0]['from_unit'] > $quantity){
                                $pricingPriceData = $pricingPrice[0];
                            }
                            elseif($pricingPrice[count($pricingPrice)-1]['to_unit'] < $quantity){
                                $pricingPriceData = $pricingPrice[count($pricingPrice)-1];
                            }
                            }
                        }
                        if (isset($pricingPriceData)) {
                            if ($is_delivery_placement === 1) {
                                $price = $pricingPriceData->rrp_delivery_placement_per_unit;
                            } else {
                                $price = $pricingPriceData->rrp_delivery_per_unit;
                            }
                        }
                    }
                }
            }

            return $price;
        }
        return false;
    }

    public function createPurchaseOrdersJson($order_list)
    {
        $po_list = [];
        foreach ($order_list as $order) {
            $po = [];
            $po_req = [];
            $po_details_list = [];
            $number_of_orders=count(array_unique( $order['orders']));
            $po_req['fld_name'] = $order['fld_name'];
            $po_req['fld_id'] = $order['fld_id'];
            $po_req['site_name'] = $order['site_name'];
            $po_req['site_id'] = $order['site_id'];
            $po_req['supply_by_date'] = $order['supply_by_date'];
            $po_req['site_details'] = $order['site_details'];
            $po_req['supplier_id'] = $order['supplier_id'];
            $po_req['cost'] = $order['cost'];
            $po_req['order_count'] = $number_of_orders;
            $po_req['items'] = $order['items'];
            $po_req['supplier'] = $order['supplier'];
            $po_req['supplier_details'] = $order['supplier_details'];
            $po_req['purchase_order'] = $order['purchase_order'];
            $po_req['as_an_agent'] = $order['as_an_agent'];
            $po_req['currency'] = $order['supplier_details']['billingDetails']['currencyDetails']['currency_name'];
            $po_req['country'] = $order['supplier_details']['region']['country']['country_name'];
            $po_req['state'] = $order['supplier_details']['region']['countryState']['state_name'];
            $po_req['is_delivery_placement'] = $order['is_delivery_placement'];



            $po_no = $this->commonSequenceRepo->getLatestReferenceIdByLoggedInTenantUser(FLD_PO_LIST_CODE_SEQUENCE);
            $po['po_no'] = $po_no['ref_number'];
            $po['po_date'] = date('d-F-Y');
            $po['org_id'] = $order['org_id'];
            $po['terr_id'] = $order['terr_id'];
            $po['del_to_org_id'] = $order['del_to_org_id'];
            $po['del_to_terr_id'] = $order['del_to_terr_id'];
            $po['cancel_by_date'] = $order['cancel_by_date'];
            $po['supply_by_date'] = $order['supply_by_date'];
            $po['fld_id'] = $order['fld_id'];
            $po['flower_drive'] = $order['fld_name'];
            $po['to_org_id'] = $order['supplier_id'];
            $po['as_an_agent'] = $order['as_an_agent'];
            $po['supplier_name'] = $order['supplier'];
            $po['supplier_address_1'] = $order['supplier_details']['regi_address_1'];
            $po['supplier_address_2'] = $order['supplier_details']['regi_address_2'];
            $po['supplier_town'] = $order['supplier_details']['region']['town_short_name'] ;
            $po['supplier_state'] = $order['supplier_details']['region']['countryState']['state_name'];
            $po['supplier_contact_email'] = $order['supplier_email'];
            $po['supplier_country'] = $order['supplier_details']['region']['country']['country_name'];
            $po['supplier_post_code'] = $order['supplier_details']['region']['postalCode']['postal_code'];
            $po['currency'] = $order['supplier_details']['billingDetails']['currencyDetails']['currency_name'];
            $po['site_logo'] = isset($order['site_details']['siteLogo']['image_crops']['large']) ? $order['site_details']['siteLogo']['image_crops']['large'] : '';
            $po['site_name'] = $order['site_details']['terr_name'];
            $po['site_address_1'] = $order['site_details']['address_1'];
            $po['site_address_2'] = $order['site_details']['address_2'];
            $po['site_town'] = $order['site_details']['region']['town_short_name'];
            $po['site_state'] = $order['site_details']['region']['countryState']['state_name'];
            $po['site_country'] = $order['site_details']['territoryCountry']['country_name'];
            $po['site_post_code'] = $order['site_details']['region']['postalCode']['postal_code'];
            $po['comp_id'] = $order['site_details']['comp_id'];
            $po['site_id'] = $order['site_details']['site_id'];
            $po['tena_type'] = $order['site_details']['tenant']['tena_type'];
            $po['site_tax_number'] = "";
            $po['del_to_name'] = $order['site_details']['terr_name'];
            $po['del_to_address_1'] = $order['site_details']['address_1'];
            $po['del_to_address_2'] =  $order['site_details']['address_2'];
            $po['del_to_town'] =  $order['site_details']['region']['town_short_name'];
            $po['del_to_state'] = $order['site_details']['region']['countryState']['state_name'];
            $po['del_to_country'] = $order['site_details']['territoryCountry']['country_name'];
            $po['del_to_post_code'] = $order['site_details']['region']['postalCode']['postal_code'];
            $po['is_delivery_placement'] = $order['is_delivery_placement'];
            $po['qty_outstanding_list'] = $order['qty_outstanding_list'];
            $po['agent_company_name'] = COMPANY_NAME;
            $po['agent_company_address1'] = COMPANY_ADDRESS1;
            $po['agent_company_address2'] = COMPANY_ADDRESS2;
            $po['agent_company_town'] = COMPANY_TOWN;
            $po['agent_company_state'] = COMPANY_STATE;
            $po['agent_company_country'] = COMPANY_COUNTRY;
            $po['agent_company_post_code'] = COMPANY_POST_CODE;
            $po['primary_contact_site_name'] = "";
            $po['primary_contact_first_name'] = "";
            $po['primary_contact_last_name'] = "";
            $po['primary_contact_phone'] = "";
            $po['primary_contact_email_address'] = "";
            $po['preview_status'] = true;
            $po['delivery_method'] = $order['is_delivery_placement'] == 0 ? 'Delivery only' : 'Delivery with placement';
            $po['number_of_orders'] = $number_of_orders;
            $total = 0;
//            ['postalCode']['postal_code']

            foreach ($order['items'] as $item) {
                $po_details = [];
                $po_details['product'] = $item['item_name'];
                $po_details['no_of_locations'] = $item['location_count'];
                $po_details['item_id'] = $item['item_id'];
                $po_details['item_type'] = $item['item_type'];
                $po_details['price_per_unit'] = $item['item_price'];
                $po_details['qty_ordered'] = $item['quantity'];
                $po_details['total'] = $item['item_price'] * $item['quantity'];
                $total += $po_details['total'];
                $po_details['item']['item_name'] = $item['item_name'];
                $po_details['consumer_order_item_ids'] = $item['consumer_order_item_ids'];
                $po_details['is_delivery_placement'] = $item['is_delivery_placement'];
                array_push($po_details_list, $po_details);
            }
            $po['total'] = $total;
            $po_req['total'] = $total;
            $po['po_details'] = $po_details_list;

            $po_req['purchase_order'] = json_encode($po);
            array_push($po_list, $po_req);
        }
        return $po_list;
    }

    public function getLogo($site_id)
    {
        return $this->_getSiteLogoAttribute($site_id);
    }

    private function _getSiteLogoAttribute($site_id)
    {
        $image = new Image();
        $img = [];
        $logo = $image->getImagesByResourceTypeAndResourceId(IMAGE_RESOURCE_TYPE_SITE_LOGO, $site_id);
        if (isset($logo[0]['image_crops']['small'])) {
            $img['url'] = $logo[0]['image_crops']['small'];
            $img['image_name'] = $logo[0]['image_name'];
            $img['status'] = true;
            return $img;
        } else {
            if (env('S3_ENABLED')) {
                $img['url'] = env('S3_BUCKET_PUBLIC_URL') . '/public/defaultImages/home-watermark-chapel.png';
            } else {
                $img['url'] = asset('/images/home-watermark-chapel.png', env('USE_HTTPS'));
            }
            // $img['url'] = asset('/images/home-watermark-chapel.png', env('USE_HTTPS'));
            $img['image_name'] = 'home-watermark-chapel.png';
            $img['status'] = false;
            return $img;
        }
    }

    public function getAllPOList($options, $tenantId = null)
    {
        if ($options['groupBy'] == 'to_org_id') {
            if (empty($options['sortBy']['column'])) {
                $options['sortBy']['type'] = 'ASC';
                $options['sortBy']['column'] = 'to_org_id';
            }
        } elseif ($options['groupBy'] == 'from_org_id') {
            if ($options['sortBy']['column'] == "") {
                $options['sortBy']['type'] = 'ASC';
                $options['sortBy']['column'] = 'from_org_id';
            }
        } elseif ($options['groupBy'] == 'fld_id') {
            if ($options['sortBy']['column'] == "") {
                $options['sortBy']['type'] = 'ASC';
                $options['sortBy']['column'] = 'fld_id';
            }
        } else if ($options['groupBy'] == 'po_status') {
            if (empty($options['sortBy']['column'])) {
                $options['sortBy']['order'] = 'DESC';
                $options['sortBy']['column'] = 'po_status';
            }
        } else if ($options['groupBy'] == 'fld_type') {
            if (empty($options['sortBy']['column'])) {
                $options['sortBy']['order'] = 'DESC';
                $options['sortBy']['column'] = 'fld_type';
            }
        } else if ($options['groupBy'] == 'none') {
            if (empty($options['sortBy']['column'])) {
                $options['sortBy']['order'] = 'DESC';
                $options['sortBy']['column'] = 'code';
            }
        }

        $userTenantId = empty($tenantId) ? getLoggedInUser()->user_tenant_id : $tenantId;
        $pOList = FlowerDrivePurchaseOrder::select('SYS_tenants.tena_name', 'SYS_territories.terr_name', 'FLD_PO_TB.fld_po_id', 'FLD_TB.fld_id', 'FLD_TB.fld_name', 'FLD_TB.complete_by_date', 'FLD_PO_TB.fld_po_no', 'FLD_PO_TB.order_total', 'FLD_PO_TB.currency',
            'FLD_PO_TB.order_date', 'FLD_PO_TB.sup_by_date', 'FLD_PO_TB.po_status', 'FLD_PO_TB.created_at', 'FLD_PO_TB.is_delivery_placement', 'FLD_PO_TB.del_to_terr_id', 'FLD_TB.fld_type')
            ->join('FLD_TB', 'FLD_TB.fld_id', '=', 'FLD_PO_TB.fld_id')
            ->join('SYS_territories', 'SYS_territories.terr_id', '=', 'FLD_PO_TB.del_to_terr_id')
            ->join('SYS_tenants', 'SYS_tenants.tena_id', '=', 'FLD_PO_TB.to_org_id')
            ->where('from_org_id', $userTenantId);

        if (array_key_exists('sortBy', $options) && !empty($options['sortBy'])) {

            if ($options['sortBy']['column'] == 'fld_po_no') {
                $pOList = $pOList->orderBy('fld_po_no', $options['sortBy']['order']);
            }
            if ($options['sortBy']['column'] == 'to_org_id') {
                $pOList = $pOList->orderBy('tena_name', $options['sortBy']['order']);
            }
            if ($options['sortBy']['column'] == 'fld_po_no') {
                $pOList = $pOList->orderBy('fld_po_no', $options['sortBy']['order']);
            }
            if ($options['sortBy']['column'] == 'from_org_id') {
                $pOList = $pOList->orderBy('terr_name', $options['sortBy']['order']);
            }
            if ($options['sortBy']['column'] == 'fld_id') {
                $pOList = $pOList->orderBy('fld_name', $options['sortBy']['order']);
            }
            if ($options['sortBy']['column'] == 'order_total') {
                $pOList = $pOList->orderBy('order_total', $options['sortBy']['order']);
            }
            if ($options['sortBy']['column'] == 'order_date') {
                $pOList = $pOList->orderBy('order_date', $options['sortBy']['order']);
            }
            if ($options['sortBy']['column'] == 'sup_by_date') {
                $pOList = $pOList->orderBy('sup_by_date', $options['sortBy']['order']);
            }
            if ($options['sortBy']['column'] == 'po_status') {
                $pOList = $pOList->orderByRaw(orderByWithNullOrEmptyLast(DB::raw('CASE WHEN FLD_PO_TB.po_status = "NOT_SENT" THEN "NOT_SENT" WHEN  FLD_PO_TB.po_status = "ORDERED" THEN "ORDERED" WHEN FLD_PO_TB.po_status = "CANCELLED" THEN "CANCELLED" WHEN FLD_PO_TB.po_status = "RECEIVED" THEN "RECEIVED" END'), $options['sortBy']['order']));
            }
            if ($options['sortBy']['column'] == 'fld_type') {
                $pOList = $pOList->orderBy('FLD_TB.fld_type', $options['sortBy']['order']);
            }

        }

        $pOList = $this->_searchChunksRawSql($pOList, $options, $userTenantId);
        if (!empty($options['paginate'])) {
            $pOList = $pOList->paginate($options['paginate']);
        } else {
            $pOList = $pOList->paginate($pOList->count());
        }
        return $pOList;
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
                if ($options['search'] != "" && isset($selectedColumn['fld_type'])) {
                    $query->orWhere('FLD_TB.fld_type', 'like', '%' . $options['search'] . '%');
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
            if (isset($options['searchByFldType']) && $options['searchByFldType'] != '') {
                $pOList->where('FLD_TB.fld_type', 'Like', '%' . $options['searchByFldType'] . '%');
            }

        }

        return $pOList;
    }


    public function getFldPoGroupCountForSuppliers($supplires, $options = [], $tenantId = null)
    {
        $userId = getLoggedInUser();
        $tenantId = !empty($tenantId) ? $tenantId : $userId['user_tenant_id'];
        $suppliresCount = [];
        $supplier = FlowerDrivePurchaseOrder::query()->select('SYS_tenants.tena_id')->where('del_to_org_id', $tenantId)
            ->join('SYS_tenants', 'SYS_tenants.tena_id', '=', 'FLD_PO_TB.to_org_id');
        if (isset($options['isReleasePo']) && $options['isReleasePo']) {
            $supplier = $supplier->where('FLD_PO_TB.po_status', '=', 'NOT_SENT')
                ->where('FLD_PO_TB.po_release_status', 'OPEN');
        }
        $supplier->selectRaw('count(tena_id) as count')->selectRaw('tena_name');
        $supplier->groupBy('to_org_id');
        $poListCountForSupplier = $supplier->get()->toArray();
        if ($poListCountForSupplier) {
            foreach ($poListCountForSupplier as $supCount) {
                $suppliresCount[str_replace(" ", "_", $supCount['tena_name'])] = $supCount['count'];
            }
        }

        return $suppliresCount;
    }

    public function getFldPoGroupCountForSite($site, $options = [], $tenantId = null)
    {
        $userId = getLoggedInUser();

        $tenantId = !empty($tenantId) ? $tenantId : $userId['user_tenant_id'];
        $fldPoSiteCount = [];
        $sites = FlowerDrivePurchaseOrder::query()->select('SYS_territories.terr_id')->where('del_to_org_id', $tenantId)
            ->join('SYS_territories', 'SYS_territories.terr_id', '=', 'FLD_PO_TB.del_to_terr_id');
        if (isset($options['isReleasePo']) && $options['isReleasePo']) {
            $sites = $sites->where('FLD_PO_TB.po_status', '=', 'NOT_SENT')
                ->where('FLD_PO_TB.po_release_status', 'OPEN');
        }
        $sites = $sites->selectRaw('count(terr_id) as count')->selectRaw('terr_name');
        $sites->groupBy('terr_id');
        $fldPoListCountForSites = $sites->get()->toArray();
        if ($fldPoListCountForSites) {
            foreach ($fldPoListCountForSites as $siteCount) {
                $fldPoSiteCount[str_replace(" ", "_", $siteCount['terr_name'])] = $siteCount['count'];
            }
        }

        return $fldPoSiteCount;
    }

    public function getFldPoGroupCountForFlowerDrive($flowerDrive, $options = [], $tenantId = null)
    {
        $userId = getLoggedInUser();

        $tenantId = !empty($tenantId) ? $tenantId : $userId['user_tenant_id'];
        $poFldCount = [];
        $fldData = FlowerDrivePurchaseOrder::query()->select('FLD_TB.fld_id')->where('del_to_org_id', $tenantId)
        ->join('FLD_TB', 'FLD_TB.fld_id', '=', 'FLD_PO_TB.fld_id');
        if (isset($options['isReleasePo']) && $options['isReleasePo']) {
            $fldData = $fldData->where('FLD_PO_TB.po_status', '=', 'NOT_SENT')
                ->where('FLD_PO_TB.po_release_status', 'OPEN');
        }
        $fldData->selectRaw('count(FLD_TB.fld_id) as count')->selectRaw('FLD_TB.fld_name');
        $fldData->groupBy('fld_id');
        $poFldCountForFlowerDrive = $fldData->get()->toArray();
        if ($poFldCountForFlowerDrive) {
            foreach ($poFldCountForFlowerDrive as $fldCount) {
                $poFldCount[str_replace(" ", "_", $fldCount['fld_name'])] = $fldCount['count'];
            }
        }

        return $poFldCount;
    }

    public function getFldPoGroupCountForFlowerDriveType($fldType, $options = [], $tenantId = null)
    {
        $userId = getLoggedInUser();

        $tenantId = !empty($tenantId) ? $tenantId : $userId['user_tenant_id'];
        $poFldTypeCount = [];
        $fldTypeData = FlowerDrivePurchaseOrder::query()->select('FLD_TB.fld_type')->where('FLD_PO_TB.del_to_org_id', $tenantId)
            ->join('FLD_TB', 'FLD_TB.fld_id', '=', 'FLD_PO_TB.fld_id');
        if (isset($options['isReleasePo']) && $options['isReleasePo']) {
            $fldTypeData = $fldTypeData->where('FLD_PO_TB.po_status', '=', 'NOT_SENT')
                ->where('FLD_PO_TB.po_release_status', 'OPEN');
        }
        $fldTypeData->selectRaw('count(FLD_TB.fld_type) as count')->selectRaw('FLD_TB.fld_type');
        $fldTypeData->groupBy('FLD_TB.fld_type');
        $poFldTypeCountForFlowerDrive = $fldTypeData->get()->toArray();
        if ($poFldTypeCountForFlowerDrive) {
            foreach ($poFldTypeCountForFlowerDrive as $fldTypeCount) {
                $poFldTypeCount[str_replace(" ", "_", $fldTypeCount['fld_type'])] = $fldTypeCount['count'];
            }
        }

        return $poFldTypeCount;
    }

    public function getFldPOGroupCountForStatus($status, $options = [], $tenantId = null)
    {
        $userId = getLoggedInUser();
        $tenantId = !empty($tenantId) ? $tenantId : $userId['user_tenant_id'];
        $statusPOListCount = [];
        $pOList = FlowerDrivePurchaseOrder::query()->where('del_to_org_id', $tenantId);
        $pOList->selectRaw('count(po_status) as count')->selectRaw('po_status');
        $pOList->groupBy('po_status');
        $pOList = $this->_searchChunksRawSql($pOList, $options, $tenantId);
        $pOListCountForStatus = $pOList->get()->toArray();
        if ($pOListCountForStatus) {
            foreach ($pOListCountForStatus as $pOListCount) {
                $statusPOListCount[str_replace(" ", "_", $pOListCount['po_status'])] = $pOListCount['count'];
            }
        }
        return $statusPOListCount;
    }

    public function addFldPOReceipt($request, $currentUserData, $fromOrgId)
    {
        $fldPurchaseOrderReceiptsData = $request['purchaseOrderReceiptData'];
        $fldPOId = $request['fldPOId'];
        $fldId = $request['fldId'];
        $receivedDate = $request['receivedDate'];
        $poFromOrgId = $fromOrgId;
        $loggedUserId = $currentUserData->id;

        $generatedPOReceiptNoData = $this->commonSequenceRepo->getLatestReferenceIdByLoggedInTenantUser(FLD_PO_RECEIPT_LIST_CODE_SEQUENCE, $poFromOrgId);

        // add PO receipt record to parent table
        $addedPOReceiptData = $this->_createFldPOReceipt($generatedPOReceiptNoData['ref_number'], $receivedDate, $fldPOId, $loggedUserId);

        if (!empty($addedPOReceiptData)) {
            // PO receipt successfully saved
            $poReceiptID = $addedPOReceiptData->po_recipt_id;

            $fldPOIsFullyReceived = true;
            foreach ($fldPurchaseOrderReceiptsData as $fldPurchaseOrderParentReceiptData) {
                $fldPOLineId = $fldPurchaseOrderParentReceiptData['po_line_id'];
                $fldPurchaseOrderId = $fldPurchaseOrderParentReceiptData['fld_po_id'];
                $existingReceivedAmountOfPOLine = $fldPurchaseOrderParentReceiptData['tot_qty_received'];

                if ($fldPurchaseOrderParentReceiptData['qty_outstanding'] > 0) {
                    $fldPOIsFullyReceived = false;
                }

                $this->_createOrUpdateFldPOLinesAndReceiptLines($fldPurchaseOrderParentReceiptData, $poReceiptID, $fldPurchaseOrderId, $loggedUserId, $existingReceivedAmountOfPOLine);
            }

            // FLD PO update status for RECEIVED
            if ($fldPOIsFullyReceived == true) {
                FlowerDrivePurchaseOrder::where('fld_po_id', $fldPOId)
                    ->update(['po_status' => 'RECEIVED']);
            }
        }

        return $addedPOReceiptData;
    }

    public function addFldPoReceiptForSheduleAllocation($poId, $currentUserData, $fromOrgId)
    {

        $fldPOId = $poId;
        $loggedUserId = $currentUserData->tenant['tena_id'];

        $generatedPOReceiptNoData = $this->commonSequenceRepo->getLatestReferenceIdByLoggedInTenantUser(FLD_PO_RECEIPT_LIST_CODE_SEQUENCE, $fromOrgId);
        // add PO receipt record to parent table
        $addedPOReceiptData = $this->_createFldPOReceiptForSheduleAllocation($generatedPOReceiptNoData['ref_number'], $fldPOId, $currentUserData->id);
        return $addedPOReceiptData;

    }

    public function _createFldPOReceiptForSheduleAllocation($poReceiptNo, $fldPOId, $loggedUserId)
    {
        $dataToSave = [
            'po_recipt_no' => $poReceiptNo,
            'recipt_date' => date('Y-m-d H:i:s'),
            'fld_po_id' => $fldPOId,
            'allocated_status' => 'COMPLETED',
            'created_by' => $loggedUserId,
        ];

        $receipt = FlowerDrivePurchaseOrderReceipt::create($dataToSave);

        return $receipt;
    }

    public function _createFldPOReceipt($poReceiptNo, $receivedDate, $fldPOId, $loggedUserId)
    {
        $dataToSave = [
            'po_recipt_no' => $poReceiptNo,
            'recipt_date' => date('Y-m-d H:i:s', strtotime($receivedDate)),
            'fld_po_id' => $fldPOId,
            'created_by' => $loggedUserId,
        ];

        return FlowerDrivePurchaseOrderReceipt::create($dataToSave);
    }

    public function addFldPoReceiptLineForSheduleAllocation($poReceiptId, $itemData, $currentUserData)
    {
        $loggedUserId = $currentUserData->id;
        $addedPOReceiptData = $this->_createFldPOReceiptLine($poReceiptId, $itemData, $loggedUserId);
        return $addedPOReceiptData;

    }

    public function _createFldPOReceiptLine($poReceiptData, $itemData, $loggedUserId)
    {
        //Check allredy added po_recipt_id and item_id
        $addedItemReciptDetails = FlowerDrivePurchaseOrderReceiptLine::where(['po_recipt_id' => $poReceiptData['po_recipt_id'],'item_id' =>$itemData->item_id])
        ->first();

        if ($addedItemReciptDetails) {
            $dataToUpdate = [
                'item_receive_qty' => $addedItemReciptDetails['item_receive_qty']+$itemData['qty'],
                'updated_by' => $loggedUserId,
            ];
            FlowerDrivePurchaseOrderReceiptLine::where('po_recipt_line_id', $addedItemReciptDetails->po_recipt_line_id)->update($dataToUpdate);
            $returnData = $addedItemReciptDetails;
        } else {
            $dataToSave = [
                'po_recipt_id' => $poReceiptData['po_recipt_id'],
                'po_line_id' => $itemData->getConsumerOrderLocationDetails->consumerOrderItem->fld_po_line_id,
                'fld_po_id' => $poReceiptData['fld_po_id'],
                'item_id' => $itemData->item_id,
                'type' => $itemData->getItemDetails->item_type,
                'item_receive_qty' => $itemData['qty'],
                'currency' => $itemData->getConsumerOrderLocationDetails->consumerOrderItem->fldPoLine->currency,
                'unit_price' => $itemData->getConsumerOrderLocationDetails->consumerOrderItem->fldPoLine->unit_price,
                'extended_cost' => $itemData->getConsumerOrderLocationDetails->consumerOrderItem->fldPoLine->extended_cost,
                'created_by' => $loggedUserId,
            ];
            $returnData = FlowerDrivePurchaseOrderReceiptLine::create($dataToSave);

        }
        return $returnData;
    }

    public function updatePoAllocationForServiceSchedule($locationId, $receiptLineData, $currentUserData, $itemData)
    {
        $loggedUserId = $currentUserData->id;
        $addedPOAllocationData = $this->_createPoAllocation($locationId, $receiptLineData, $loggedUserId, $itemData);
        return $addedPOAllocationData;
    }

    public function _createPoAllocation($locationId, $receiptLineData, $loggedUserId, $itemData)
    {
        $dataToSave = [
            'consumer_order_loc_id' => $locationId,
            'allocated_qty' => $itemData['qty'],
            'po_recipt_id' => $receiptLineData->po_recipt_id,
            'po_recipt_line_id' => $receiptLineData->po_recipt_line_id,
            'created_by' => $loggedUserId,
        ];

        return FlowerDrivePurchaseOrderAllocation::create($dataToSave);
    }

    public function _createOrUpdateFldPOLinesAndReceiptLines($fldPurchaseOrderParentReceiptData, $poReceiptId, $fldPOId, $loggedUserId, $existingReceivedAmountOfPOLine)
    {
        $fldPOLineId = $fldPurchaseOrderParentReceiptData['po_line_id'];

        // add parent row to receipt line table
        $parentReceiptLineDataToSave = [
            'po_recipt_id' => $poReceiptId,
            'po_line_id' => $fldPOLineId,
            'fld_po_id' => $fldPOId,
            'item_id' => $fldPurchaseOrderParentReceiptData['item']['item_id'],
            'type' => $fldPurchaseOrderParentReceiptData['item']['item_type'],
            'item_receive_qty' => $fldPurchaseOrderParentReceiptData['new_received_amount'],
            'currency' => $fldPurchaseOrderParentReceiptData['currency'],
            'unit_price' => $fldPurchaseOrderParentReceiptData['unit_price'],
            'extended_cost' => $fldPurchaseOrderParentReceiptData['extended_cost'],
            'created_by' => $loggedUserId,
        ];

        $createdParentReceiptData = FlowerDrivePurchaseOrderReceiptLine::create($parentReceiptLineDataToSave);
        $createdParentReceiptLineId = $createdParentReceiptData->po_recipt_line_id;
        $createdParentReceiptLineReceivedQty = $createdParentReceiptData->item_receive_qty;

        // item allocation process
        $this->_consumerOrderItemAllocation($fldPOId, $fldPOLineId, $poReceiptId, $createdParentReceiptLineId, $createdParentReceiptLineReceivedQty);

        $totalQuantityReceived = 0;

        // parent quantity received amount
        $totalQuantityReceived = $totalQuantityReceived + $fldPurchaseOrderParentReceiptData['new_received_amount'];

        foreach ($fldPurchaseOrderParentReceiptData['substitutes'] as $fldPurchaseOrderChildReceiptData) {
            // substitute quantity received amount
            $totalQuantityReceived = $totalQuantityReceived + $fldPurchaseOrderChildReceiptData['new_received_amount'];
            if (isset($fldPurchaseOrderChildReceiptData['is_new_substitute'])) {

                $selectedItemIdForSubstitute = $fldPurchaseOrderChildReceiptData['item_id'];
                $selectedItemTypeForSubstitute = $fldPurchaseOrderChildReceiptData['item_type'];

                //create record to purchase order line - substitute
                $dataToSave = [
                    'fld_po_id' => $fldPOId,
                    'item_id' => $selectedItemIdForSubstitute,
                    'type' => $selectedItemTypeForSubstitute,
                    'item_qty_received' => $fldPurchaseOrderChildReceiptData['new_received_amount'],
                    'currency' => $fldPurchaseOrderParentReceiptData['currency'],
                    'unit_price' => $fldPurchaseOrderParentReceiptData['unit_price'],
                    'extended_cost' => $fldPurchaseOrderParentReceiptData['extended_cost'],
                    'is_substitute' => 1,
                    'subsitute_parent' => $fldPOLineId,
                    'created_by' => $loggedUserId,
                ];

                $createdPOLineDataToSubstitute = FlowerDrivePurchaseOrderLine::create($dataToSave);
                $substituteFldPOLineId = $createdPOLineDataToSubstitute->po_line_id;
            } else {

                $selectedItemIdForSubstitute = $fldPurchaseOrderChildReceiptData['item']['item_id'];
                $selectedItemTypeForSubstitute = $fldPurchaseOrderChildReceiptData['item']['item_type'];

                //update record to purchase order line - substitute
                $substituteFldPOLineId = $fldPurchaseOrderChildReceiptData['po_line_id'];
                $updatedPOLineDataToSubstitute = FlowerDrivePurchaseOrderLine::where('po_line_id', $substituteFldPOLineId)
                    ->update([
                        'item_qty_received' => $fldPurchaseOrderChildReceiptData['new_received_amount'],
                        'updated_by' => $loggedUserId,
                    ]);
            }

            // add new row to receipt line table - substitute
            $childReceiptLineDataToSave = [
                'po_recipt_id' => $poReceiptId,
                'po_line_id' => $substituteFldPOLineId,
                'fld_po_id' => $fldPOId,
                'item_id' => $selectedItemIdForSubstitute,
                'type' => $selectedItemTypeForSubstitute,
                'item_receive_qty' => $fldPurchaseOrderChildReceiptData['new_received_amount'],
                'currency' => $fldPurchaseOrderParentReceiptData['currency'],
                'unit_price' => $fldPurchaseOrderParentReceiptData['unit_price'],
                'extended_cost' => $fldPurchaseOrderParentReceiptData['extended_cost'],
                'created_by' => $loggedUserId,
            ];
            $createdChildReceiptData = FlowerDrivePurchaseOrderReceiptLine::create($childReceiptLineDataToSave);
            $createdChildReceiptLineId = $createdChildReceiptData->po_recipt_line_id;
            $createdChildReceiptLineReceivedQty = $createdChildReceiptData->item_receive_qty;

            // item allocation process
            $this->_consumerOrderItemAllocation($fldPOId, $fldPOLineId, $poReceiptId, $createdChildReceiptLineId, $createdChildReceiptLineReceivedQty);
        }

        // update existing row in PO line table - parent
        $updatedDataToParent = FlowerDrivePurchaseOrderLine::where('po_line_id', $fldPOLineId)
            ->update([
                'tot_qty_received' => isset($fldPurchaseOrderParentReceiptData['new_total_received_amount']) ? $fldPurchaseOrderParentReceiptData['new_total_received_amount'] : $existingReceivedAmountOfPOLine,
                'item_qty_received' => $fldPurchaseOrderParentReceiptData['new_received_amount'],
                'qty_outstanding' => $fldPurchaseOrderParentReceiptData['qty_outstanding'],
                'updated_by' => $loggedUserId,
            ]);
    }

    public function separateIsDeliveryPos($list)
    {
        $po_list = [];
        $is_delivery = [];
        $is_delivery_placement = [];
        if (!empty($list)) {
            foreach ($list as $po_order) {
                foreach ($po_order['items'] as $item) {
                    if ($item['is_delivery_placement'] == 0) {
                        array_push($is_delivery, $po_order);
                    } else {
                        array_push($is_delivery_placement, $po_order);
                    }
                }
            }
            $new_delivery = [];
            $new_delivery_placement = [];
            if (!empty($is_delivery)) {
                foreach ($is_delivery as $delivery) {
                    $d_item = [];
                    foreach ($delivery['items'] as $item) {
                        if ($item['is_delivery_placement'] == 0) {
                            array_push($d_item, $item);
                        }
                    }
                    $cost = 0;
                    $count = 0;
                    foreach ($d_item as $item) {
                        $cost += $item['item_price'] * $item['quantity'];
                        $count += $item['quantity'];
                    }
                    $delivery['cost'] = $cost;
                    $delivery['order_count'] = $count;
                    $delivery['items'] = $d_item;
                    $delivery['is_delivery_placement'] = 0;
                    array_push($new_delivery, $delivery);
                }
            }
            if (!empty($is_delivery_placement)) {
                foreach ($is_delivery_placement as $delivery) {
                    $dp_item = [];
                    foreach ($delivery['items'] as $item) {
                        if ($item['is_delivery_placement'] == 1) {
                            array_push($dp_item, $item);
                        }
                    }
                    $cost = 0;
                    $count = 0;
                    foreach ($dp_item as $item) {
                        $cost += $item['item_price'] * $item['quantity'];
                        $count += $item['quantity'];
                    }
                    $delivery['cost'] = $cost;
                    $delivery['order_count'] = $count;
                    $delivery['items'] = $dp_item;
                    $delivery['is_delivery_placement'] = 1;
                    array_push($new_delivery_placement, $delivery);
                }
            }
//            array_push($po_list,$new_delivery);
//            array_push($po_list,$new_delivery_placement);
            $po = [];
            $new_delivery = array_unique($new_delivery, SORT_REGULAR);
            foreach ($new_delivery as $delivery) {
                array_push($po, $delivery);
            }
            $new_delivery_placement = array_unique($new_delivery_placement, SORT_REGULAR);
            foreach ($new_delivery_placement as $delivery) {
                array_push($po, $delivery);
            }
            return $po;
        }
        return [];
    }

    public function updateConsumerOrderItemPoLine($consumerOrderItemId, $poLineId, $poId)
    {
        $updateConsumerOrderLine = ConsumerOrderItem::whereIn('consumer_order_item_id', $consumerOrderItemId)->update([
            'fld_po_id' => $poId,
            'fld_po_line_id' => $poLineId
        ]);
        return $updateConsumerOrderLine;

    }

    public function updateConsumerOrderItem($po_id, $supp_id)
    {

        $updateConsumerOrderLine2 = ConsumerOrderItem::where('fld_po_id', $po_id)->update([
            'po_supp_id' => $supp_id
        ]);
    }

    public function updateConsumerOrderItemQtyOutStandings($consumer_order_item_id, $qty_outstanding)
    {
        $updateConsumerOrderLine1 = ConsumerOrderItem::where('consumer_order_item_id', $consumer_order_item_id)->update([
            'qty_outstanding' => $qty_outstanding
        ]);
    }

    public function _consumerOrderItemAllocation($fldPOId, $fldPOLineId, $poReceiptId, $poReceiptLineId, $poReceiptLineReceivedQty)
    {
        if (!empty($poReceiptLineReceivedQty)) {

            // get all consumer order items based on fld_po_id and fld_po_line_id
            $consumerOrderItems = ConsumerOrderItem::where(['fld_po_id' => $fldPOId, 'fld_po_line_id' => $fldPOLineId])
                ->select('consumer_order_item_id', 'qty_outstanding')
                ->with(['consumerOrderItemLocations' => function ($q) {
                    $q->select('consumer_order_loc_id', 'consumer_order_item_id', 'qty_outstanding');
                }])
                ->where('is_pkg', 0)
                ->where('qty_outstanding', '!=', 0)
                ->orderBy('created_at', 'ASC')
                ->get();

            $remainQtyToAllocate = 0;
            $allocatedQty = 0;

            foreach ($consumerOrderItems as $consumerOrderItem) {
                if ($poReceiptLineReceivedQty > 0) {
                    if ($consumerOrderItem->qty_outstanding <= $poReceiptLineReceivedQty) {
                        $allocatedQty = $consumerOrderItem->qty_outstanding;
                        $remainQtyToAllocate = 0;
                        $poReceiptLineReceivedQty = $poReceiptLineReceivedQty - $allocatedQty;
                    } else {
                        $allocatedQty = $poReceiptLineReceivedQty;
                        $remainQtyToAllocate = $consumerOrderItem->qty_outstanding - $allocatedQty;
                        $poReceiptLineReceivedQty = $poReceiptLineReceivedQty - $allocatedQty;
                    }

                    // consumer order item allocation
                    $consumerOrderItemsUpdate = ConsumerOrderItem::where('consumer_order_item_id', $consumerOrderItem->consumer_order_item_id)
                        ->update(['qty_outstanding' => $remainQtyToAllocate]);

                    // consumer order item locations allocation
                    $this->_consumerOrderItemLocationsAllocation($consumerOrderItem->consumerOrderItemLocations, $poReceiptId, $poReceiptLineId, $allocatedQty);
                }
            }
        }
    }

    public function _consumerOrderItemLocationsAllocation($consumerOrderItemLocations, $poReceiptId, $poReceiptLineId, $consumerOrderItemAllocatedQty)
    {
        foreach ($consumerOrderItemLocations as $consumerOrderItemLocation) {
            if ($consumerOrderItemAllocatedQty > 0) {
                $allocatedQty = 0;
                $isFullyAllocated = 0;
                if ($consumerOrderItemLocation->qty_outstanding <= $consumerOrderItemAllocatedQty) {
                    $allocatedQty = $consumerOrderItemLocation->qty_outstanding;
                    $remainQtyToAllocate = 0;
                    $consumerOrderItemAllocatedQty = $consumerOrderItemAllocatedQty - $allocatedQty;
                } else {
                    $allocatedQty = $consumerOrderItemAllocatedQty;
                    $remainQtyToAllocate = $consumerOrderItemLocation->qty_outstanding - $allocatedQty;
                    $consumerOrderItemAllocatedQty = $consumerOrderItemAllocatedQty - $allocatedQty;
                }

                $dataToUpdate['qty_outstanding'] = $remainQtyToAllocate;

                //  check consumer order item location is fully allocated or not
                if ($remainQtyToAllocate <= 0) {
                    $dataToUpdate['is_allocated'] = 1;
                }

                // consumer order item location allocation
                $consumerItemLocAllocationData = ConsumerOrderItemLocation::where('consumer_order_loc_id', $consumerOrderItemLocation->consumer_order_loc_id)
                    ->update($dataToUpdate);

                // add new row to po allocation table based on consumer order item location allocation
                $poAllocationData = FlowerDrivePurchaseOrderAllocation::create([
                    'consumer_order_loc_id' => $consumerOrderItemLocation->consumer_order_loc_id,
                    'allocated_qty' => $allocatedQty,
                    'po_recipt_id' => $poReceiptId,
                    'po_recipt_line_id' => $poReceiptLineId,
                ]);
            }
        }
    }

    public function addFldPOReturnLine($request)
    {
        $code_review=0;
        $fldPOReturnLineData = [];
        $pOReciptLineId = $request['pOReciptLineId'];
        $returnedDate = $request['returnedDate'];
        $qtyReturned = $request['qtyReturned'];
        $fldPOLineId = $request['pOLineId'];
        $fldPOId = $request['fldPOId'];
        $itemReceiveQty = $request['itemReceiveQty'];
        $pOReciptLineData = $request['pOReciptLineData'];
        $loggedUserTenantId = getLoggedInUser()->tenant['tena_id'];

        if (!empty($pOReciptLineId))
        {
            $qty_outstanding=$this->_createOrUpdateFldPOReturnLines($pOReciptLineId,$returnedDate, $qtyReturned,$loggedUserTenantId,$fldPOLineId, $fldPOId,$itemReceiveQty,$pOReciptLineData);
            $fLDPurchaseOrderData = FlowerDrivePurchaseOrder::where(['fld_po_id' => $fldPOId])
            ->first();
            if($fLDPurchaseOrderData['po_status'] == 'RECEIVED' && $qty_outstanding > 0)
            {
                $pODataToUpdate = [
                    'po_status' => 'ORDERED',
                    'updated_at' => date('Y-m-d H:i:s', strtotime($returnedDate)),
                    'updated_by' => getLoggedInUser()->id,
                ];
                if(!$code_review){
                    $updatedPOData = FlowerDrivePurchaseOrder::where('fld_po_id', $fldPOId)
                    ->update($pODataToUpdate);
                }
            }
            return $fldPOReturnLineData;
        }
    }

    public function _createOrUpdateFldPOReturnLines($pOReciptLineId, $returnedDate, $qtyReturned, $loggedUserTenantId, $fldPOLineId, $fldPOId, $itemReceiveQty, $pOReciptLineData)
    {
        $code_review = 0;

        //Get FlowerDrivePurchaseOrderLine data
        $fLDPurchaseOrderLineData = FlowerDrivePurchaseOrderLine::where(['fld_po_id' => $fldPOId, 'po_line_id' => $fldPOLineId])
            ->first();
        $isSubstitute = $fLDPurchaseOrderLineData->is_substitute;
        $subsituteParent = $fLDPurchaseOrderLineData->subsitute_parent;

        $returnLineDataToSave = [
            'po_recipt_line_id' => $pOReciptLineId,
            'returned_date' => date('Y-m-d', strtotime($returnedDate)),
            'qty_returned' => $qtyReturned,
            'created_by' => getLoggedInUser()->id,
        ];
        if (!$code_review) {
            $createdReturnLineData = FlowerDrivePurchaseOrderReturnLine::create($returnLineDataToSave);
        }


        $reciptLineDataToUpdate = [
            'item_return_qty' => $pOReciptLineData['item_return_qty'] + $qtyReturned,
            'updated_at' => date('Y-m-d H:i:s', strtotime($returnedDate)),
            'updated_by' => getLoggedInUser()->id,
        ];
        if (!$code_review) {
            $updatedPOReceptLineData = FlowerDrivePurchaseOrderReceiptLine::where('po_recipt_line_id', $pOReciptLineId)
                ->update($reciptLineDataToUpdate);
        }


        if ($isSubstitute) {
            $pOLineDataToUpdate = [
                'item_qty_returned' => $fLDPurchaseOrderLineData->item_qty_returned + $qtyReturned,
                'updated_at' => date('Y-m-d H:i:s', strtotime($returnedDate)),
                'updated_by' => getLoggedInUser()->id,
            ];
            if (!$code_review) {
                $updatedPOReceptData = FlowerDrivePurchaseOrderLine::where('po_line_id', $pOReciptLineData['purchase_order_line']['po_line_id'])
                    ->update($pOLineDataToUpdate);
            }

            $fLDPurchaseOrderLineParentData = FlowerDrivePurchaseOrderLine::where(['fld_po_id' => $fldPOId, 'po_line_id' => $subsituteParent])
                ->first();
            $parePOLineDataToUpdate = [
                'qty_outstanding' => $fLDPurchaseOrderLineParentData->qty_outstanding + $qtyReturned,
                'tot_qty_returned' => $fLDPurchaseOrderLineParentData->tot_qty_returned + $qtyReturned,
                'updated_at' => date('Y-m-d H:i:s', strtotime($returnedDate)),
                'updated_by' => getLoggedInUser()->id,
            ];
            if(!$code_review){
            $updatedPOReceptData = FlowerDrivePurchaseOrderLine::where('po_line_id', $subsituteParent)
                ->update($parePOLineDataToUpdate);

            }

            $this->_returnItemAllocation($pOReciptLineId, $subsituteParent, $qtyReturned, $fldPOId,$itemReceiveQty,$pOReciptLineData);

            return $qty_outstanding=$fLDPurchaseOrderLineParentData->qty_outstanding + $qtyReturned;

        } else {
            $pOLineDataToUpdate = [
                'item_qty_returned' => $fLDPurchaseOrderLineData->item_qty_returned + $qtyReturned,
                'qty_outstanding' => $fLDPurchaseOrderLineData->qty_outstanding + $qtyReturned,
                'tot_qty_returned' => $fLDPurchaseOrderLineData->tot_qty_returned + $qtyReturned,
                'updated_at' => date('Y-m-d H:i:s', strtotime($returnedDate)),
                'updated_by' => getLoggedInUser()->id,
            ];
            if (!$code_review) {
                $updatedPOReceptData = FlowerDrivePurchaseOrderLine::where('po_line_id', $pOReciptLineData['purchase_order_line']['po_line_id'])
                    ->update($pOLineDataToUpdate);
            }

            $this->_returnItemAllocation($pOReciptLineId, $fldPOLineId, $qtyReturned, $fldPOId,$itemReceiveQty,$pOReciptLineData);

            return $qty_outstanding=$fLDPurchaseOrderLineData->qty_outstanding + $qtyReturned;
        }


    }

    public function _returnItemAllocation($pOReciptLineId, $fldPOLineId, $qtyReturned, $fldPOId, $itemReceiveQty,$pOReciptLineData)
    {
        $code_review=0;
         if (!empty($qtyReturned)) {

            // get all consumer order items based on fld_po_id and fld_po_line_id
            $consumerOrderItems = ConsumerOrderItem::where(['fld_po_id' => $fldPOId, 'fld_po_line_id' => $fldPOLineId])
                ->where('is_pkg', 0)
                ->orderBy('created_at', 'ASC')
                ->get();
            $remainQtyToAllocate = 0;
            $allocatedQty = 0;
            $remainQtyToReturnForConsumer = $qtyReturned;
            foreach ($consumerOrderItems as $consumerOrderItem) {
                if ($remainQtyToReturnForConsumer) {
                    $newReturnQty = 0;
                    $reservedQty = 0;
                    $newQtyOutstanding = 0;
                    $itemReturnQty = 0;
                    $reservedQty = $consumerOrderItem->quantity - $consumerOrderItem->qty_outstanding;
                    if ($remainQtyToReturnForConsumer <= $reservedQty) {
                        $itemReturnQty = $remainQtyToReturnForConsumer;
                    } else {
                        $itemReturnQty = $reservedQty;
                    }
                    $itemReturnQty = $remainQtyToReturnForConsumer;
                    if ($itemReturnQty) {
                        $newQtyOutstanding = $consumerOrderItem->qty_outstanding + $itemReturnQty;
                        if (!$code_review) {
                            $consumerOrderItemsUpdate = ConsumerOrderItem::where('consumer_order_item_id', $consumerOrderItem->consumer_order_item_id)
                                ->update(['qty_outstanding' => $newQtyOutstanding, 'updated_by' => getLoggedInUser()->id]);
                        }
                        $remainQtyToReturnForConsumer = $remainQtyToReturnForConsumer - $itemReturnQty;
                    }

                    //Get CONSUMER_ORDER_ITEM_LOC_TB data
                    $consumerOrderItemLocations = ConsumerOrderItemLocation::where(['consumer_order_item_id' => $consumerOrderItem->consumer_order_item_id])
                    ->orderBy('created_at','ASC')
                    ->get();
                    $remainQtyToConsumerOrderOutstanding = $itemReturnQty;
                    foreach ($consumerOrderItemLocations as $consumerOrderItemLocation) {
                        if ($remainQtyToConsumerOrderOutstanding) {
                            //Update location data
                            $orderItemLocationReturnQty = 0;
                            $consumerOrderItemLocationReservedQty = 0;
                            $consumerOrderItemLocationReservedQty = $consumerOrderItemLocation->item_qty - $consumerOrderItemLocation->qty_outstanding;
                            if ($consumerOrderItemLocationReservedQty >= $remainQtyToConsumerOrderOutstanding) {
                                $orderItemLocationReturnQty = $remainQtyToConsumerOrderOutstanding;
                            } else {
                                $orderItemLocationReturnQty = $consumerOrderItemLocationReservedQty;
                            }

                        $qty_outstanding=$consumerOrderItemLocation->qty_outstanding+$orderItemLocationReturnQty;

                            //Update CONSUMER_ORDER_ITEM_LOC_TB table outstanding
                            if (!$code_review) {
                                $cousumerOrderItemAlocationUpdate = ConsumerOrderItemLocation::where('consumer_order_loc_id', $consumerOrderItemLocation->consumer_order_loc_id)
                                    ->update(['qty_outstanding' => $qty_outstanding, 'updated_at' => date('Y-m-d H:i:s')]);
                            }

                        //Update allocation table data
                        $allocatedQty=$consumerOrderItemLocation->item_qty-$qty_outstanding;
                        if(!$code_review){
                         $orderAllocationUpdate = FlowerDrivePurchaseOrderAllocation::where(['consumer_order_loc_id'=>$consumerOrderItemLocation->consumer_order_loc_id,'po_recipt_line_id'=>$pOReciptLineId])
                          ->update(['allocated_qty' => $allocatedQty, 'updated_at' => date('Y-m-d H:i:s')]);
                        }

                            $remainQtyToConsumerOrderOutstanding = $remainQtyToConsumerOrderOutstanding - $orderItemLocationReturnQty;
                        }
                    }

                    if ($remainQtyToReturnForConsumer == 0) {
                        break;
                    }
                }
            }
        }
    }

    // supplier Portal
    public function getPurchaseOrderRecievedDetails($options, $tenantId = null)
    {
        $completeByDate = '';
        $orderByDate = '';
        $siteId = '';
        $searchByPoNumber = '';
        $searchByFldType = '';

        if($options['searchByPurchaseNumber']){
            $searchByPoNumber = $options['searchByPurchaseNumber'];
        }
        if($options['searchByPoSite']){
            $siteId = $options['searchByPoSite'];
        }


        if($options['searchByPoCompletedDate']){
            $completeByDate = date("Y-m-d", strtotime($options['searchByPoCompletedDate']));
        }
        if($options['searchByPoOrderDate']){
            $orderByDate = date("Y-m-d", strtotime($options['searchByPoOrderDate']));
        }
        if($options['searchByFldType']){
            $searchByFldType = $options['searchByFldType'];
        }


        $userId = getLoggedInUser();
        $tenantId = !empty($tenantId) ? $tenantId : $userId['user_tenant_id'];

        $pOList =  FlowerDrivePurchaseOrder::select(DB::raw('(select currency from FLD_PO_LINE_TB where fld_po_id  =   FLD_PO_LINE_TB.fld_po_id limit 1) as currency')  , 'FLD_PO_TB.fld_po_id',  'FLD_PO_TB.fld_po_no', 'FLD_PO_TB.order_total', 'SYS_territories.terr_name','FLD_TB.order_by_date','FLD_TB.complete_by_date', 'FLD_TB.fld_name', 'FLD_PO_TB.released_date', 'FLD_TB.fld_type')
                    ->join('FLD_TB', 'FLD_TB.fld_id', '=', 'FLD_PO_TB.fld_id')
                    ->join('SYS_territories', 'SYS_territories.terr_id', '=', 'FLD_PO_TB.del_to_terr_id')
                    ->where('FLD_PO_TB.to_org_id', '=', $tenantId)
                    ->where('FLD_PO_TB.po_status', '!=' ,'NOT_SENT');

        if (!empty($options['order_from_started_date']) && !empty($options['order_to_started_date'])) {
            $pOList = $pOList->whereBetween('FLD_TB.order_by_date', [$this->formatDate($options['order_from_started_date']), $this->formatDate($options['order_to_started_date'])]);
        }elseif(!empty($options['order_from_started_date']) && empty($options['order_to_started_date'])){
            $pOList = $pOList->whereDate('FLD_TB.order_by_date', '>=', $this->formatDate($options['order_from_started_date']) );
        }elseif(empty($options['order_from_started_date']) && !empty($options['order_to_started_date'])){
        $pOList = $pOList->whereDate('FLD_TB.order_by_date', '<=', $this->formatDate($options['order_to_started_date']) );
        }

        if (!empty($options['complete_from_started_date']) && !empty($options['complete_to_started_date'])) {
            $pOList = $pOList->whereBetween('FLD_TB.complete_by_date', [$this->formatDate($options['complete_from_started_date']), $this->formatDate($options['complete_to_started_date'])]);
        }elseif(!empty($options['complete_from_started_date']) && empty($options['complete_to_started_date'])){
            $pOList = $pOList->whereDate('FLD_TB.complete_by_date', '>=', $this->formatDate($options['complete_from_started_date']) );
        }elseif(empty($options['complete_from_started_date']) && !empty($options['complete_to_started_date'])){
        $pOList = $pOList->whereDate('FLD_TB.complete_by_date', '<=', $this->formatDate($options['complete_to_started_date']) );
        }

        if($siteId){
            $pOList->where('SYS_territories.terr_id', '=', $siteId);
        }

        if($searchByPoNumber){
            $pOList->where('FLD_PO_TB.fld_po_no', '=', $searchByPoNumber);
        }
        if($searchByFldType){
            $pOList->where('FLD_TB.fld_type', 'LIKE', '%'. $searchByFldType .'%');
        }

        if($options['search']){

            $pOList = $pOList->where(function ($query) use ($options) {
                $query
                    ->orWhere('FLD_PO_TB.fld_po_no', 'like', '%' . $options['search'] . '%')
                    ->orWhere('FLD_PO_TB.currency', 'like', '%' . $options['search'] . '%')
                    ->orWhere('FLD_PO_TB.order_total', 'like', '%' . $options['search'] . '%')
                    ->orWhere('FLD_TB.order_by_date', 'like', '%' . $options['search'] . '%')
                    ->orWhere('FLD_TB.complete_by_date', 'like', '%' . $options['search'] . '%')
                    ->orWhere('SYS_territories.terr_name', 'like', '%' . $options['search'] . '%')
                    ->orWhere('FLD_TB.fld_type', 'like', '%' . $options['search'] . '%')
                    ;
            });
        }

        if($options['groupBy']){
            if($options['groupBy'] == 'to_org_id'){
                if (empty($options['sortBy']['column'])) {
                    $options['sortBy']['order'] = 'ASC';
                    $options['sortBy']['column'] = 'SYS_territories.terr_name';
                }
            }
            if($options['groupBy'] == 'fld_type'){
                if (empty($options['sortBy']['column'])) {
                    $options['sortBy']['order'] = 'ASC';
                    $options['sortBy']['column'] = 'fld_type';
                }
            }
        }

        if (array_key_exists('sortBy', $options) && !empty($options['sortBy']['column'])) {
            if ($options['sortBy']['column'] == 'fld_po_no') {
                $pOList = $pOList->orderBy('FLD_PO_TB.fld_po_no', $options['sortBy']['order']);
            }
            if ($options['sortBy']['column'] == 'from_org_id') {
                $pOList = $pOList->orderBy('SYS_territories.terr_name', $options['sortBy']['order']);
            }
            if ($options['sortBy']['column'] == 'order_total') {
                $pOList = $pOList->orderBy('FLD_PO_TB.order_total', $options['sortBy']['order']);
            }
            if ($options['sortBy']['column'] == 'order_date') {
                $pOList = $pOList->orderBy('FLD_TB.order_by_date', $options['sortBy']['order']);
            }
            if ($options['sortBy']['column'] == 'sup_by_date') {
                $pOList = $pOList->orderBy('FLD_TB.complete_by_date', $options['sortBy']['order']);
            }
            if ($options['sortBy']['column'] == 'fld_type') {
                $pOList = $pOList->orderByRaw(orderByWithNullOrEmptyLast(DB::raw('CASE WHEN FLD_TB.fld_type = "CAMPAIGN" THEN "CAMPAIGN" WHEN  FLD_TB.fld_type = "STORE" THEN "STORE" END'), $options['sortBy']['order']));
            }
        }else{
            $pOList = $pOList->orderBy('FLD_PO_TB.fld_po_no', 'ASC');
        }
        if (!empty($options['paginate'])) {
            $pOList = $pOList->paginate($options['paginate']);
        } else {
            $pOList = $pOList->get();
        }

        return $pOList;
    }

    private function formatDate($opt){
        return date("Y-m-d", strtotime($opt));
    }

    private function _searchChunksRawSqlPoRecieved($pOList, $options)
    {
        if ($options['columns'] != null) {
            $selectedColumn = array_flip($options['columns']);
        }
        $options['search'] = wildCardCharacterReplace($options['search']);
        $search = wildCardCharacterReplace($options['search']);

        if($search){
            $pOList = $pOList->Where('FLD_PO_TB.fld_po_no', 'like', '%' . $options['search'] . '%')
                                ->orWhere('SYS_territories.terr_name', 'like', '%' . $options['search'] . '%')
                                ->orWhere('FLD_TB.order_by_date', 'like', '%' . $options['search'] . '%')
                                ->orWhere('FLD_TB.complete_by_date', 'like', '%' . $options['search'] . '%')
                                ->orWhere('FLD_PO_TB.order_total', 'like', '%' . $options['search'] . '%')
                                ;
        }
        return $pOList;
    }

    public function getpoRecievedGroupCountForSuppliers($supplires, $options = [], $tenantId = null)
    {
        $userId = getLoggedInUser();
        $tenantId = !empty($tenantId) ? $tenantId : $userId['user_tenant_id'];
        $suppliresCount = [];
        $supplier = FlowerDrivePurchaseOrder::query()->select('to_org_id')->where('created_by', $tenantId);
        $supplier->selectRaw('count(to_org_id) as count')->selectRaw('to_org_id');
        $supplier->groupBy('to_org_id');
        $poListCountForSupplier = $supplier->get()->toArray();
        if ($poListCountForSupplier) {
            foreach ($poListCountForSupplier as $supCount) {
                $suppliresCount[str_replace(" ", "_", $supCount['to_org_id'])] = $supCount['count'];
            }
        }
        return $suppliresCount;
    }

    public function getAllServiceSchedules($id, $option)
    {
        $list = FlowerDriveServiceScheduleLine::select('fld_schdl_id', 'fld_schdl_line_id', 'location_id', 'is_checked')
            ->with(['getSheduleItems' => function ($q) {
                $q->select('fld_schdl_item_id', 'fld_schdl_line_id', 'item_id', 'is_checked', 'customer_order_loc_id')
                    ->with(['getItemDetails' => function ($q) {
                        $q->select('item_id', 'item_name')
                            ->without('images');
                    }, 'getConsumerOrderLocationDetails' => function ($q) {
                        $q->select('consumer_order_loc_id', 'consumer_order_item_id')->with(['consumerOrderItem' => function ($q) {
                            $q->select('consumer_order_item_id', 'consumer_order_id')->with(['consumerOrder' => function ($q) {
                                $q->select('consumer_order_id', 'order_number', 'terr_id');
                            }]);
                        }]);
                    }]);
            }, 'location' => function ($q) {
                $q->select('id', 'name', 'code', 'site_area_id', 'section_or_area_id', 'location_type_id', 'latitude', 'longitude')->with(['siteArea' => function ($q) {
                    $q->select('id', 'area_name');
                }, 'sectionOrArea' => function ($q) {
                    $q->select('sec_or_area_id', 'section_or_area_name');
                }, 'LocationType' => function ($q) {
                    $q->select('id', 'type_name');
                }
                ]);
            }, 'getScheduleDetails' => function ($q) {
                $q->select('fld_schdl_id', 'is_confirmed');
            }])->whereHas('getScheduleDetails', function ($q) use ($id) {
                $q->where('fld_po_id', $id);
            });

        if (array_key_exists('sortBy', $option) && !empty($option['sortBy'])) {
            if ($option['sortBy']['column'] == 'location') {
                $list = $list->
                whereHas('location', function ($q) use ($option) {
                    $q->orderBy('name', $option['sortBy']['order']);
                });
            }
        }
        $list = $this->_searchChunksRawSqlForServiceSchedule($list, $option);

        if (!empty($option['isPaginate']) && $option['isPaginate'] == true && !empty($option['paginate'])) {
            $list = $list->paginate($option['paginate']);
        } else {
            $list = $list->paginate($list->count());
        }
        return $list;

    }

    public function getImagesForSrvScheduleAllocation($srv_line_id){
        $data = FlowerDriveServiceScheduleLine::select('fld_schdl_line_id')->where('fld_schdl_line_id', $srv_line_id)->first();
        return $data;
    }


    public function updateServiceAllocation($location)
    {
        $location_update = FlowerDriveServiceScheduleLine::where('fld_schdl_line_id', $location['fld_schdl_line_id'])->update(['is_checked' => $location['is_checked']]);
    }

    public function updateServiceAllocationItem($item)
    {
        $items = FlowerDriveServiceScheduleLineItem::where('fld_schdl_item_id', $item['fld_schdl_item_id'])->update(['is_checked' => $item['is_checked']]);
    }

    private function _searchChunksRawSqlForServiceSchedule($lists, $options)
    {
        $options['search'] = wildCardCharacterReplace($options['search']);
        if (isset($options['search']) && !empty($options['search']))
            $lists->whereHas('location', function ($q) use ($options) {
                $q->where('locations.name', 'like', '%' .
                    $options['search'] . '%')
                    ->orWhere('locations.code', 'like', '%' .
                        $options['search'] . '%')
                    ->orWhere('locations.latitude', 'like', '%' .
                        $options['search'] . '%')
                    ->orWhere('locations.longitude', 'like', '%' .
                        $options['search'] . '%');
            });

        return $lists;
    }

    public function changePoAllocatedStatus($fldPoId, $allocatedStatus)
    {
        $poIsUpdated = FlowerDrivePurchaseOrder::where('fld_po_id', $fldPoId)
            ->update(['allocated_status' => $allocatedStatus]);

        return $poIsUpdated;
    }

    public function getPoAllocatedStatus($fldPoId)
    {
        $allocatedStatus = '';
        $poAllocatedStatus = FlowerDrivePurchaseOrder::select('allocated_status')
            ->where('fld_po_id', $fldPoId)
            ->first();

        if (!empty($poAllocatedStatus)) {
            $allocatedStatus = $poAllocatedStatus->allocated_status;
        }

        return $allocatedStatus;
    }

    public function getServiceScheduleAllocationsForConfirm($id)
    {
        $userTenantId = empty($tenantId) ? getLoggedInUser()->user_tenant_id : $tenantId;
        $list = FlowerDriveServiceScheduleLine::select('fld_schdl_id', 'fld_schdl_line_id', 'location_id', 'is_checked')
            ->whereHas('getSheduleItems', function ($query) {
                $query->where('is_checked', true);
            })->whereHas('getScheduleDetails', function ($q) use ($id) {
                $q->where('fld_po_id', $id);
            })->with(['getSheduleItems' => function ($q) {
                $q->select('fld_schdl_item_id', 'fld_schdl_line_id', 'item_id', 'is_checked', 'customer_order_loc_id')
                    ->where('is_checked', true)
                    ->with(['getConsumerOrderLocationDetails' => function ($q) {
                        $q->select('consumer_order_loc_id', 'consumer_order_item_id')
                            ->with(['consumerOrderItem' => function ($q) {
                                $q->select('consumer_order_item_id', 'fld_po_line_id', 'quantity', 'item_price', 'consumer_order_id')
                                    ->with(['fldPoLine' => function ($q) {
                                        $q->select('po_line_id', 'qty_ordered', 'currency', 'unit_price', 'extended_cost', 'type');
                                    }, 'consumerOrder' => function ($q) {
                                        $q->select('consumer_order_id', 'billing_info');
                                    }]);
                            }]);
                    }
                        , 'getItemDetails' => function ($q) {
                            $q->select('item_id', 'item_type', 'item_name');
                        }]);
            }])->get();

        return $list;
    }

    public function getPoSiteDetailsForServiceScheduleAllocation($id)
    {

        $siteDetails = FlowerDrivePurchaseOrder::select('del_to_terr_id', 'fld_id', 'fld_po_no', 'fld_po_id')
            ->where('fld_po_id', $id)->with(['territory' => function ($q) {
                $q->select('terr_id', 'terr_name', 'contact_phone', 'contact_email', 'address_1', 'address_2', 'website_url');
            }, 'flowerDrive' => function ($q) {
                $q->select('fld_id', 'fld_name')->with(['lines' => function($q) {
                    $q->select('fld_id', 'fld_line_id', 'item_id');
                }]);
            },'getServiceSchedule' => function($q) {
                $q->select('fld_schdl_id', 'fld_po_id')->with(['FlowerDriveServiceScheduleLines' => function($q) {
                    $q->select('fld_schdl_id', 'fld_schdl_line_id');
                }]);
            }])->get();

        return $siteDetails;
    }

    public function saveGenerateTempPoDetails($pOTemp)
    {
         return FlowerDriveGeneratePurchaseOrderTemp::create($pOTemp);
    }

    public function getGenerateTempPoDetails($options, $tenantId = null)
    {
        if ($options['groupBy'] == 'supplier') {
            if ($options['sortBy']['column'] == "") {
                $options['sortBy']['type'] = 'ASC';
                $options['sortBy']['column'] = 'supplier';
            }
        } else if ($options['groupBy'] == 'site') {
            if ($options['sortBy']['column'] == "") {
                $options['sortBy']['type'] = 'ASC';
                $options['sortBy']['column'] = 'site';
            }
        } else if ($options['groupBy'] == 'flower_drive') {
            if ($options['sortBy']['column'] == "") {
                $options['sortBy']['type'] = 'ASC';
                $options['sortBy']['column'] = 'flower_drive';
            }
        } else if ($options['groupBy'] == 'supply_by_date') {
            if (empty($options['sortBy']['column'])) {
                $options['sortBy']['order'] = 'ASC';
                $options['sortBy']['column'] = 'supply_by_date';
            }
        } else if ($options['groupBy'] == 'none') {
            if (empty($options['sortBy']['column'])) {
                $options['sortBy']['order'] = 'DESC';
                $options['sortBy']['column'] = 'po_no';
            }
        }
        $userId = getLoggedInUser();
        $tenantId = !empty($tenantId) ? $tenantId : $userId['user_tenant_id'];

        $tempPoDetails = FlowerDriveGeneratePurchaseOrderTemp::select('sup.tena_name as sup_tena_name', 'org.tena_name as org_tena_name','terr.terr_name as terr_name','temp_fld_po_id','fld_po_no','fld_name','no_of_orders','cost','supply_by_date','po_orders_json','selected_fld_ids_json','selected_site_ids_json')
        ->join('SYS_tenants as sup', 'TEMP_FLD_PO_TB.to_org_id', '=','sup.tena_id')
        ->join('SYS_tenants as org', 'TEMP_FLD_PO_TB.from_org_id', '=', 'org.tena_id')
        ->join('SYS_territories as terr', 'TEMP_FLD_PO_TB.del_to_terr_id', '=', 'terr.terr_id')

        ->where('from_org_id', $tenantId);

        if (array_key_exists('sortBy', $options) && !empty($options['sortBy'])) {
            if ($options['sortBy']['column'] == 'supplier') {
                $tempPoDetails = $tempPoDetails->orderBy('sup.tena_name', $options['sortBy']['order']);
            }
            if ($options['sortBy']['column'] == 'site') {
                $tempPoDetails = $tempPoDetails->orderBy('terr.terr_name', $options['sortBy']['order']);
            }
            if ($options['sortBy']['column'] == 'flower_drive') {
                $tempPoDetails = $tempPoDetails->orderBy('TEMP_FLD_PO_TB.fld_name', $options['sortBy']['order']);
            }
            if ($options['sortBy']['column'] == 'order_count') {
                $tempPoDetails = $tempPoDetails->orderBy('TEMP_FLD_PO_TB.no_of_orders', $options['sortBy']['order']);
            }
            if ($options['sortBy']['column'] == 'cost') {
                $tempPoDetails = $tempPoDetails->orderBy('TEMP_FLD_PO_TB.cost', $options['sortBy']['order']);
            }
            if ($options['sortBy']['column'] == 'supply_by_date') {
                $tempPoDetails = $tempPoDetails->orderBy('TEMP_FLD_PO_TB.supply_by_date', $options['sortBy']['order']);
            }
        }

        if (!empty($options['paginate'])) {
            $tempPoDetails = $tempPoDetails->paginate($options['paginate']);
        } else {
            $tempPoDetails = $tempPoDetails->paginate($tempPoDetails->count());
        }
        return $tempPoDetails;
    }

    public function getGenerateTempPoGroupCountForSuppliers($supplires, $options = [], $tenantId = null)
    {
        $userId = getLoggedInUser();
        $tenantId = !empty($tenantId) ? $tenantId : $userId['user_tenant_id'];
        $suppliresCount = [];
        $supplier = FlowerDriveGeneratePurchaseOrderTemp::query()->select('to_org_id')->where('from_org_id', $tenantId);
        $supplier->selectRaw('count(to_org_id) as count')->selectRaw('to_org_id');
        $supplier->groupBy('to_org_id');
        $poListCountForSupplier = $supplier->get()->toArray();
        if ($poListCountForSupplier) {
            foreach ($poListCountForSupplier as $supCount) {
                $suppliresCount[str_replace(" ", "_", $supCount['to_org_id'])] = $supCount['count'];
            }
        }
        return $suppliresCount;
    }

    public function getGenerateTempPoGroupCountForSite($site, $options = [], $tenantId = null)
    {
        $userId = getLoggedInUser();

        $tenantId = !empty($tenantId) ? $tenantId : $userId['user_tenant_id'];
        $fldPoSiteCount = [];
        $sites = FlowerDriveGeneratePurchaseOrderTemp::query()->select('from_org_id')->where('from_org_id', $tenantId);
        $sites->selectRaw('count(from_org_id) as count')->selectRaw('from_org_id');
        $sites->groupBy('from_org_id');
        $fldPoListCountForSites = $sites->get()->toArray();
        if ($fldPoListCountForSites) {
            foreach ($fldPoListCountForSites as $siteCount) {
                $fldPoSiteCount[str_replace(" ", "_", $siteCount['from_org_id'])] = $siteCount['count'];
            }
        }

        return $fldPoSiteCount;
    }

    public function getGenerateTempPoGroupCountForFlowerDrive($flowerDrive, $options = [], $tenantId = null)
    {
        $userId = getLoggedInUser();

        $tenantId = !empty($tenantId) ? $tenantId : $userId['user_tenant_id'];
        $poFldCount = [];
        $fldData = FlowerDriveGeneratePurchaseOrderTemp::query()->select('fld_name')->where('from_org_id', $tenantId);
        $fldData->selectRaw('count(fld_name) as count')->selectRaw('fld_name');
        $fldData->groupBy('fld_name');
        $poFldCountForFlowerDrive = $fldData->get()->toArray();
        if ($poFldCountForFlowerDrive) {
            foreach ($poFldCountForFlowerDrive as $fldCount) {
                $poFldCount[str_replace(" ", "_", $fldCount['fld_name'])] = $fldCount['count'];
            }
        }

        return $poFldCount;
    }

    public function getGenerateTempPoGroupCountForSupplyByDate($flowerDrive, $options = [], $tenantId = null)
    {
        $userId = getLoggedInUser();

        $tenantId = !empty($tenantId) ? $tenantId : $userId['user_tenant_id'];
        $supplyDateCount = [];
        $supDateData = FlowerDriveGeneratePurchaseOrderTemp::query()->select('fld_name')->where('from_org_id', $tenantId);
        $supDateData->selectRaw('count(fld_name) as count')->selectRaw('fld_name');
        $supDateData->groupBy('fld_name');
        $supplyDateCountForTempFldPo = $supDateData->get()->toArray();
        if ($supplyDateCountForTempFldPo) {
            foreach ($supplyDateCountForTempFldPo as $dateCount) {
                $supplyDateCount[str_replace(" ", "_", $dateCount['fld_name'])] = $dateCount['count'];
            }
        }

        return $supplyDateCount;
    }

    public function deleteGenerateTempPoDetailsSaved($tenantId)
    {
        $isDeletedSuccess = FlowerDriveGeneratePurchaseOrderTemp::where('from_org_id', $tenantId)->delete();
        return $isDeletedSuccess;
    }

    public function getAllPurchaseOrdersToSave($tenantId){
        $poList = FlowerDriveGeneratePurchaseOrderTemp::select('po_orders_json')
            ->where('from_org_id', $tenantId)
            ->get();
        return $poList;
    }



    public function updateConsumerOrderItemLocForAllocation($item)
    {
//        dd($item);
        $consumer_order_items = ConsumerOrderItemLocation::where('consumer_order_loc_id', $item['getConsumerOrderLocationDetails']['consumer_order_loc_id'])
            ->update(['is_allocated' => true, 'is_placed' => true, 'qty_outstanding' => 0]);


        return $consumer_order_items;
    }

    public function deleteGenerateTempPoDetails($tenantId)
    {
//        $tenantId = getLoggedInUser()->user_tenant_id;
        $isDeletedSuccess = FlowerDriveGeneratePurchaseOrderTemp::where('from_org_id', $tenantId)->delete();
        return $isDeletedSuccess;
    }

    public function updatePoStatus($columnName, $status, $poIdsArr = [], $poId = null)
    {
        $query = FlowerDrivePurchaseOrder::select('fld_po_id');

        if (!empty($poIdsArr)) {
            $query->whereIn('fld_po_id', $poIdsArr);
        } else {
            $query->where('fld_po_id', $poId);
        }

        $isUpdated = $query->update([$columnName => $status]);

        return $isUpdated;
    }

    public function getServiceSchedules($options)
    {
        $userId = getLoggedInUser();
        $tenantId = !empty($tenantId) ? $tenantId : $userId['user_tenant_id'];
        $serviceSchedules = FlowerDriveServiceSchedule::select('po.fld_po_id as fld_po_id','po.fld_po_no as fld_po_no', 'supp_by_date', 'schdl_status', 'po.fld_id as fld_id', 'fld.fld_type as fld_type')
            ->where('supp_id', $tenantId)
            ->join('FLD_PO_TB as po', 'FLD_SRV_SCHDL_TB.fld_po_id', '=', 'po.fld_po_id')
            ->join('FLD_TB as fld', 'po.fld_id', '=', 'fld.fld_id');
//            ->with(['poDetails' => function ($q) {
//                $q->select('fld_po_no', 'fld_po_id');
//            }]);

        $serviceSchedules = $this->_searchChunksRawSqlForServiceScheduleList($serviceSchedules, $options, $tenantId);
        if (!empty($options['searchPoNumber'])) {
            $serviceSchedules = $serviceSchedules->where('po.fld_po_no', 'Like', '%' . $options['searchPoNumber'] . '%');
        }
        if (!empty($options['searchFldType'])) {
            $serviceSchedules = $serviceSchedules->where('fld.fld_type', '=', $options['searchFldType']);
        }
        if (array_key_exists('sortBy', $options) && !empty($options['sortBy'])) {
            if ($options['sortBy']['column'] == 'fld_po_no') {
                $serviceSchedules = $serviceSchedules->orderBy('po.fld_po_no', $options['sortBy']['order']);
            }
            if ($options['sortBy']['column'] == 'schdl_status') {
                $serviceSchedules = $serviceSchedules->orderBy('FLD_SRV_SCHDL_TB.schdl_status', $options['sortBy']['order']);
            }
            if ($options['sortBy']['column'] == 'supp_by_date') {
                $serviceSchedules = $serviceSchedules->orderBy('FLD_SRV_SCHDL_TB.supp_by_date', $options['sortBy']['order']);
            }
            if ($options['sortBy']['column'] == 'fld_type') {
                $serviceSchedules = $serviceSchedules->orderByRaw(orderByWithNullOrEmptyLast(DB::raw('CASE WHEN fld.fld_type = "CAMPAIGN" THEN "CAMPAIGN" WHEN  fld.fld_type = "STORE" THEN "STORE" END'), $options['sortBy']['order']));
            }
        }

        if (!empty($options['paginate'])) {
            $serviceSchedules = $serviceSchedules->paginate($options['paginate']);
        } else {
            $serviceSchedules = $serviceSchedules->paginate($serviceSchedules->count());
        }
        return $serviceSchedules;
    }

    private function _searchChunksRawSqlForServiceScheduleList($lists, $options, $tenantId)
    {
        if ($options['columns'] != null) {
            $selectedColumn = array_flip($options['columns']);
        }
        $options['search'] = wildCardCharacterReplace($options['search']);

        if ($options['search'] != "" && (isset($selectedColumn['fld_po_no']))) {
            $lists->where('po.fld_po_no', 'like', '%' . $options['search'] . '%');
        }
        if ($options['search'] != "" && (isset($selectedColumn['schdl_status']))) {
            $lists->orWhere('schdl_status', 'like', '%' . $options['search'] . '%')->where('supp_id', $tenantId);;
        }
        if ($options['search'] != "" && (isset($selectedColumn['supp_by_date']))) {
            $lists->orWhere('supp_by_date', 'like', '%' . $options['search'] . '%')->where('supp_id', $tenantId);;
        }
        if ($options['search'] != "" && (isset($selectedColumn['fld_type']))) {
            $lists->orWhere('fld.fld_type', 'like', '%' . $options['search'] . '%')->where('supp_id', $tenantId);;
        }
//        $options['search'] = wildCardCharacterReplace($options['search']);
//        if (isset($options['search']) && !empty($options['search']))
//            $lists->whereHas('location', function ($q) use ($options) {
//                $q->where('locations.name', 'like', '%' .
//                    $options['search'] . '%');
//            });

        return $lists;
    }

    public function getConsumerOrderIdListForEmail($id)
    {
        $poId = $id;
        $idList = [];
        $consumerOrderIds = ConsumerOrder::select('consumer_order_id')->whereHas('orderItems', function ($query) use ($poId) {
            $query->select('consumer_order_id')->where('fld_po_id', $poId);
        })->get();

        foreach ($consumerOrderIds as $id) {
            array_push($idList, $id->consumer_order_id);
        }

        return $idList;

    }

    public function sendEmailToConfirmAllocation($consumerOrderIds, $poDetails)
    {
        try{
            $consumerOrderDetails = $this->_getConsumerOrderDetailsById($consumerOrderIds);
            foreach ($consumerOrderDetails as $consumerOrder) {
                //get consumer details by consumer order item details
                $emailAddress = json_decode($consumerOrder->billing_info)->email;
                $email = new FldPurchaseOrderScheduleAllocationConfirmEmail($poDetails, $consumerOrder);
                Mail::to($emailAddress)->send($email);
            }

        }catch(\Exception $e){
            logErrors(__LINE__, $e->getLine(), $e->getMessage(), $e->getFile());
        }
    }

    private function _getConsumerOrderDetailsById($consumerOrderIds)
    {//dd($consumerOrderIds);

        $cosnumerOrderDeatils = ConsumerOrder::select('consumer_order_id', 'consumer_id', 'billing_info', 'order_number')
            ->whereIn('consumer_order_id', $consumerOrderIds)
            ->with(['orderItems' => function ($q) {
                $q->select('consumer_order_item_id', 'consumer_order_id', 'item_id', 'order_item_name')->where('is_pkg', 0)->with(['orderItemLocDetails' => function ($q) {
                    $q->select('consumer_order_item_id', 'consumer_order_loc_id', 'location_id', 'is_placed', 'decease_person_id')->with(['location' => function ($q) {
                        $q->select('id', 'name');
                    }, 'person' => function($q) {
                        $q->select('id', 'first_name', 'last_name');
                    }]);
                }, 'item' => function ($q) {
                    $q->select('item_id', 'item_name');
                }]);
            }])->get();

        return $cosnumerOrderDeatils;

    }


    public function getAllPurchaseOrderSuppliers($tenantId = null){//dd($tenantId);
        $data = [];
        $terrIds = Territory::where('terr_tenant_id', $tenantId)
            ->select('terr_id')
            ->without('companyMainUser', 'users', 'tenant', 'territoryPermission')
            ->get()
            ->pluck('terr_id')
            ->toArray();

        $data = CareProgramSupplierPermission::whereIn('from_territory_id', $terrIds)
            ->select('id', 'to_territory_id')
            ->whereHas('toTerritory' , function ($q) {
                $q->select('terr_id', 'terr_name','terr_tenant_id');
            })->with(['toTerritory'=>function($q){
                $q->select('terr_id', 'terr_name','terr_tenant_id');
                $q->without('companyMainUser', 'users', 'territoryPermission');
                $q->with(['tenant'=>function($q){
                    $q->select('tena_id','tena_name');
                }]);
        }])->get();
        return $data;
    }

    public function getServiceScheduleDetailsByFldPoId($id, $tenantId = "")
    {
        $details = FlowerDriveServiceSchedule::select('fld_po_id', 'fld_id', 'supp_id','fld_schdl_id','supp_by_date')
            ->where('fld_po_id', $id)
            ->with(['flowerDrive' => function ($q) {
                $q->select('fld_id', 'fld_name', 'complete_by_date', 'supply_by_date', 'fld_type');
            }])
            ->with(['poDetails' => function ($q) {
                $q->select('fld_po_id', 'fld_po_no','to_org_id', 'del_to_terr_id', 'from_terr_id')
                ->with(['supplier' => function ($q) {
                    $q->select('tena_id', 'tena_name', 'registration_id')
                        ->without('oxConfiguration','registration');
                }])->with(['territory' => function ($q) {
                    $q->select('terr_id', 'terr_name')
                        ->without('companyMainUser', 'territoryPermission', 'users');
                }]);
            }])
            ->with(['FlowerDriveServiceScheduleLines' => function ($q) {
                $q->select('fld_schdl_line_id','fld_schdl_id','location_id','decease_id')
                ->with(['getSheduleItems' => function ($q) {
                    $q->select('fld_schdl_line_id', 'item_id', 'qty', 'customer_order_loc_id')
                    ->with(['getItemDetails' => function ($q) {
                        $q->select('item_id', 'item_name', 'supplier_item_code');
                    }])
                    ->with(['getConsumerOrderLocationDetails' => function ($q) {
                        $q->select('consumer_order_loc_id', 'consumer_order_item_id')
                        ->with(['consumerOrderItem' => function ($q) {
                            $q->select('consumer_order_item_id', 'consumer_order_id')
                            ->with(['consumerOrder' => function ($q) {
                                $q->select('consumer_id', 'consumer_order_id', 'order_number', 'billing_info');
                            }]);
                        }]);
                    }]);
                }])
                ->with(['location' => function ($q) {
                    $q->select('id', 'code', 'name','location_type_id', 'latitude', 'longitude', 'site_area_id', 'section_or_area_id')
                    ->with(['LocationType' => function ($q) {
                        $q->select('id', 'type_name', 'option_value');
                    },'siteArea', 'sectionOrArea']);
                }])->with(['person' => function ($q) {
                    $q->select('id','first_name','last_name');

                }]);
            }])
            ->first();

        return $details;
    }

    public function updateServiceScheduleIsConfirmed($po_id)
    {
        return FlowerDriveServiceSchedule::where('fld_po_id', $po_id)->update(['is_confirmed' => true]);
    }

    public function getPoNumberList($tenantId = null)
    {
        $userTenantId = !empty($tenantId) ? getLoggedInUser()->user_tenant_id : $tenantId;
        $poNoList = FlowerDrivePurchaseOrder::select('fld_po_id', 'fld_po_no')
            ->where('to_org_id', $userTenantId)
            ->where('po_status', '!=', 'NOT_SENT')
            ->get();

        return $poNoList;
    }

    /**
     * Get purchasers order data for selected columns
     * @param Array $selectedColumnsArr Selected Columns | ex : ['fld_po_id', from_org_id]
     * @param Int $poId Purchase Order Id | ex: 12
     * @return Array Purchase order data for selected columns
     */
    public function getSelectedDataForPurchaseOrder($selectedColumnsArr, $poId)
    {
        $poDetails = [];

        if (!empty($selectedColumnsArr)) {
            $poDetails = FlowerDrivePurchaseOrder::addSelect($selectedColumnsArr)->where('fld_po_id', $poId)->first();
        }

        return $poDetails;
    }

    /**
     * Update purchase order line
     * @param Array
     * @param Int
     * @return Array
     */
    public function updateFldPoLineForSheduleAllocation($itemData, $currentUserData)
    {
        $loggedUserId = $currentUserData->id;
        $updatedPOLineData = $this->_updateFldPOLine($itemData, $loggedUserId);
        return $updatedPOLineData;
    }

    public function _updateFldPOLine($itemData, $loggedUserId)
    {
        //get po line details
        $po_line_id = $itemData->getConsumerOrderLocationDetails->consumerOrderItem->fld_po_line_id;
        $quantity = $itemData['qty'];
        $poLineDetails = FlowerDrivePurchaseOrderLine::where(['po_line_id' => $po_line_id,'item_id' =>$itemData->item_id])
        ->first();

        if ($poLineDetails) {
            $dataToUpdate = [
                'item_qty_received' => $poLineDetails['item_qty_received']+$quantity,
                'tot_qty_received' => $poLineDetails['tot_qty_received']+$quantity,
                'qty_outstanding' => $poLineDetails['qty_outstanding']-$quantity,
                'updated_by' => $loggedUserId,
            ];
            $updatedData = FlowerDrivePurchaseOrderLine::where('po_line_id', $po_line_id)->update($dataToUpdate);
        }
        return $updatedData;
    }

    private function _getCurrentDateByTimeZone($time_zone_id = null)
    {
        $timezone = "UTC";
        if ($time_zone_id != null) {
            $timezone_data = TimeZone::query()->select('Timezone')->where('id', $time_zone_id)->first();
            if ($timezone_data != null) {
                $timezone = $timezone_data->Timezone;
            }
        }
        $date = Carbon::now($timezone);
        $formattedDate = $date->format('Y-m-d');

        return $formattedDate;

    }

    public function generatePurchaseOrderByConsumerOrderId($consumerOrderId)
    {
        $purchaseOrderProcessed = false;
        if ($consumerOrderId) {
            $consumerOrderData = ConsumerOrder::select('consumer_order_id')
                ->where('consumer_order_id', $consumerOrderId)
                ->whereHas('orderItems', function ($query) {
                    $query->where('is_pkg', 0)
                        ->whereNull('fld_po_id')
                        ->whereNull('fld_po_line_id');
                })
                ->first();

            if ($consumerOrderData) {
                $orderList = $this->getOrderListForPurchaseOrders([], [], $consumerOrderData->consumer_order_id);
                if ($orderList) {
                    $listedPoDetails = $this->separateIsDeliveryPos($orderList);
                    $poList = $this->createPurchaseOrdersListForCounsumerOrderId($listedPoDetails);
                    $response = [];

                    foreach ($poList as $purchaseOrder) {
                        $saveData = [
                            'fld_po_no' => $purchaseOrder['fld_po_no'],
                            'fld_id' => $purchaseOrder['fld_id'],
                            'as_an_agent' => $purchaseOrder['as_an_agent'],
                            'from_org_id' => $purchaseOrder['from_org_id'],
                            'from_terr_id' => $purchaseOrder['from_terr_id'],
                            'del_to_org_id' => $purchaseOrder['del_to_org_id'],
                            'del_to_terr_id' => $purchaseOrder['del_to_terr_id'],
                            'to_org_id' => $purchaseOrder['to_org_id'],
                            'currency' => $purchaseOrder['currency'],
                            'order_total' => $purchaseOrder['total'],
                            'order_date' => date('Y-m-d'),
                            'number_of_orders' => $purchaseOrder['number_of_orders'],
                            'sup_by_date' => $purchaseOrder['supply_by_date'],
                            'is_delivery_placement' => $purchaseOrder['is_delivery_placement']
                        ];

                        $savedPurchaseOrder = $this->createPoOrders($saveData);
                        $purchaseOrderId = $savedPurchaseOrder->fld_po_id;
                        $this->savePurchaseOrderLines($purchaseOrderId, $purchaseOrder, $savedPurchaseOrder->to_org_id);
                        array_push($response, $savedPurchaseOrder);

                    }
                    $purchaseOrderProcessed = true;
                }
            }
        }

        return $purchaseOrderProcessed;
    }

    private function updateQtyOutstanding($qty_outstanding_list)
    {
        foreach ($qty_outstanding_list as $qty) {
            $this->updateConsumerOrderItemQtyOutStandings($qty->consumer_order_item_id, $qty->qty_outstanding);
        }
    }

    public function savePurchaseOrderLines($purchaseOrderId, $purchaseOrder, $toOrgId)
    {
        $savedPurchaseOrderLinesData = [];
        foreach ($purchaseOrder['po_lines'] as $poLine) {
            $saveData = [
                'fld_po_id' => $purchaseOrderId,
                'item_id' => $poLine['item_id'],
                'type' => $poLine['item_type'],
                'qty_ordered' => $poLine['qty_ordered'],
                'qty_outstanding' => $poLine['qty_outstanding'],
                'currency' => $poLine['currency'],
                'unit_price' => $poLine['price_per_unit'],
                'extended_cost' => $poLine['extended_cost'],
                'is_delivery_placement' => $poLine['is_delivery_placement'],
            ];
            $savedPurchaseOrderLine = $this->createPoLine($saveData);
            $this->updateConsumerOrderItemPoLine($poLine['consumer_order_item_ids'], $savedPurchaseOrderLine->po_line_id, $purchaseOrderId);
            $this->updateConsumerOrderItem($purchaseOrderId, $toOrgId);
            array_push($savedPurchaseOrderLinesData, $savedPurchaseOrderLine);
        }

        return $savedPurchaseOrderLinesData;
    }

    public function createPurchaseOrdersListForCounsumerOrderId($order_list)
    {
        $poList = [];
        foreach ($order_list as $order) {
            $po = [];
            $poLineList = [];
            $number_of_orders = count(array_unique($order['orders']));
            $po_no = $this->commonSequenceRepo->getLatestReferenceIdByLoggedInTenantUser(FLD_PO_LIST_CODE_SEQUENCE, $order['org_id']);

            $po['fld_po_no'] = $po_no['ref_number'];
            $po['fld_id'] = $order['fld_id'];
            $po['as_an_agent'] = $order['as_an_agent'];
            $po['from_org_id'] = $order['org_id'];
            $po['from_terr_id'] = $order['as_an_agent'] === 1 ? null : $order['terr_id'];
            $po['del_to_org_id'] = $order['del_to_org_id'];
            $po['del_to_terr_id'] = $order['del_to_terr_id'];
            $po['to_org_id'] = $order['supplier_id'];
            $po['currency'] = $order['supplier_details']['billingDetails']['currencyDetails']['currency_name'];
            $po['number_of_orders'] = $number_of_orders;
            $po['supply_by_date'] = $order['supply_by_date'];
            $po['is_delivery_placement'] = $order['is_delivery_placement'];

            // to future use
            $po['qty_outstanding_list'] = $order['qty_outstanding_list'];

            $total = 0;
            foreach ($order['items'] as $item) {
                $poLine = [];
                // to future use
                $poLine['no_of_locations'] = $item['location_count'];
                $poLine['item_id'] = $item['item_id'];
                $poLine['item_type'] = $item['item_type'];
                $poLine['qty_ordered'] = $item['quantity'];

                // first time qty_ordered and qty_outstanding are equal
                $poLine['qty_outstanding'] = $item['quantity'];
                $poLine['currency'] = $order['supplier_details']['billingDetails']['currencyDetails']['currency_name'];
                $poLine['price_per_unit'] = $item['item_price'];
                $poLine['extended_cost'] = $item['item_price'] * $item['quantity'];

                // to make total value of PO
                $total += $poLine['extended_cost'];
                $poLine['is_delivery_placement'] = $item['is_delivery_placement'];

                //consumer order items ids based on this po line
                $poLine['consumer_order_item_ids'] = $item['consumer_order_item_ids'];
                array_push($poLineList, $poLine);
            }
            $po['total'] = $total;
            $po['po_lines'] = $poLineList;
            array_push($poList, $po);
        }
        return $poList;
    }

    public function createArrayForServiceSchedulePDFDownload($order, $user)
    {
        $data = [];
        $service['fld_po_id'] = $order->fld_po_id ? $order->fld_po_id : '';
        $service['fld_id'] = $order->fld_id;
        $service['supp_id'] = $order->to_org_id;
        $service['supp_by_date'] = $order->sup_by_date;
        $service['fld_po_id'] = $order->fld_po_id;
        $service['fld_id'] = $order->fld_id;
        $service['supp_id'] = $order->to_org_id;
        $service['supp_by_date'] = $order->supp_by_date ? date('d-F-Y', strtotime($order->supp_by_date)) : "";

        $fld['fld_po_no'] = isset($order->poDetails->fld_po_no) ? $order->poDetails->fld_po_no : "";;
        $fld['complete_by_date'] = date('d-F-Y', strtotime($order->flowerDrive->complete_by_date));
        $fld['terr_name'] = isset($order->poDetails['territory']['terr_name']) ? $order->poDetails['territory']['terr_name'] : "";
        $fld['supplier_name'] = isset($order->poDetails['supplier']['tena_name']) ? $order->poDetails['supplier']['tena_name'] : "";
        $fld['flower_drive_name'] = $order->flowerDrive->fld_name;
        $fld['fld_type'] = $order->flowerDrive->fld_type;
        $locations = [];

        if(isset($order->FlowerDriveServiceScheduleLines) && $order->FlowerDriveServiceScheduleLines->count() > 0){
            foreach($order->FlowerDriveServiceScheduleLines as $location){
                $locationData = [];
                $quantity = [];
                $itemData = [];
                $deceaseData = [];
                $locationData['location_code'] = $location->location['code'];
                $locationData['location_name'] = $location->location['name'];
                $locationData['area'] = $location->location['siteArea'] != null ? $location->location['siteArea']->area_name : '';
                $locationData['section'] = $location->location['sectionOrArea'] != null ? $location->location['sectionOrArea']->section_or_area_name : '';
                $locationData['latitude'] = $location->location['latitude'];
                $locationData['longitude'] = $location->location['longitude'];
                $deceaseData['decease_name'] = $location['person']->first_name . ' ' . $location['person']->last_name;
                $deceaseData['location_type'] = $location['location']['LocationType']['option_value'];
                $itemData['item_name']="";
                if($location->getSheduleItems->count() > 0){
                    foreach($location->getSheduleItems as $item){
                        $itemData['order_number'] = "-";
                        if($itemData['item_name']) $itemData['item_name'] .= ", ";
                        $itemData['item_name'] = $item->getItemDetails['item_name'];
                        $itemData['supplier_item_code'] = $item->getItemDetails['supplier_item_code'];
                        $itemData['qty'] = $item['qty'];
                        if (isset($item->getConsumerOrderLocationDetails->consumerOrderItem->consumerOrder['order_number'])) {
                            $itemData['order_number'] = $item->getConsumerOrderLocationDetails->consumerOrderItem->consumerOrder['order_number'];
                        }

                        $locations[] = [
                            'locationData' => $locationData,
                            'deceaseData' => $deceaseData,
                            'itemData' => $itemData,
                            'LINE' => $locationData,
                            'ITEM' => $itemData,
                        ];
                    }
                }
            }
            $data['locations'] = isset($locations) ? $locations : [];
        }
        $data['service'] = $service;
        $data['fld'] = $fld;
        return $data;
    }
}
