<?php

use App\Modules\FlowerDrive\Http\Controllers\ManageFlowerDriveController;
use App\Modules\FlowerDrive\Http\Controllers\FlowerDrivePurchaseOrderController;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your module. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/
Route::group(['prefix' => 'v1'], function () {

    Route::post('consumer-registration', 'ConsumerProfileController@registerConsumer');
    Route::post('person-register-check', 'ConsumerProfileController@personRegisterCheck');
    Route::post('unsubscribe-fld', 'ManageFlowerDriveController@unsubscribeFLD');

    //Generate purchase orders for selected consumer order ID
    Route::post('/flower-drive/generate-po-for-consumer-order', 'FlowerDrivePurchaseOrderController@generatePurchaseOrderByConsumerOrderId');

    //Temporary solution for FLD unsubscribe
    Route::get('fld-unsubscribe/{contact_id}', 'ManageFlowerDriveController@tempFLDunsubscribe');

    Route::group(['prefix' => '/consumer', 'middleware' => ['api', 'jwt.auth']], function () {

        Route::get('get-order-list', 'OrderController@getOrderList');
        Route::get('get-order-details/{id}/{org_id}', 'OrderController@getOrderDetails');
        Route::get('get-invoice-details/{id}/{org_id}', 'OrderController@getInvoiceDetails');
        Route::get('get-consumer-state/{id}', 'OrderController@getConsumerState');
        Route::get('get-all-consumer-details-by-id/{id}', 'ConsumerProfileController@getAllConsumerDetailsById');
        Route::put('update-consumer-details-by-id/{id}', 'ConsumerProfileController@updateConsumerDetails');
        Route::get('get-receipt-details/{id}/{org_id}', 'OrderController@getReceiptDetails');
        Route::get('download-invoice', 'OrderController@downloadInvoice');
        Route::get('download-receipt', 'OrderController@downloadReceipt');
        Route::get('send-invoice/{id}/{org_id}', 'OrderController@sendInvoice');
        Route::get('send-receipt/{id}/{org_id}', 'OrderController@sendReceipt');
        Route::post('consumer-update/{id}', 'ConsumerProfileController@consumerUpdate');
        Route::post('order-cancel-update', 'InternalCheckoutController@cancelStatusUpdate');

    });

    Route::group(['prefix' => '/internal-checkout', 'middleware' => ['api', 'jwt.auth']], function () {
        Route::get('get-deceases/{id}/{terr_id}', 'InternalCheckoutController@getDeceases');
        Route::get('get-fld-details/{id}/{terr_id}', 'InternalCheckoutController@getFlowerDriveDetails');
        Route::get('get-fld/{id}', 'InternalCheckoutController@getFlowerDrive');
        Route::post('order-create', 'InternalCheckoutController@proceedCheckout');
        Route::post('save-cart-session-id', 'InternalCheckoutController@saveCartSessionIdCookies');
        Route::post('save-billing-details', 'InternalCheckoutController@saveBillingDetails');
        Route::get('get-order-details/{terr_id}', 'InternalCheckoutController@getOrderDetails');
        Route::get('get-person-details/{id}', 'InternalCheckoutController@getPersonDetails');
        Route::get('get-invoice-details/{id}', 'InternalCheckoutController@getInvoiceDetails');
        Route::get('download-invoice', 'InternalCheckoutController@downloadInvoice');
        Route::get('send-email-with-invoice/{id}', 'InternalCheckoutController@sendEmailWithInvoice');
        Route::post('add-payment', 'InternalCheckoutController@paymentProcess');
        Route::get('get-payment-method/{id}', 'InternalCheckoutController@getPaymentMethod');
//        Route::get('get-person-details/{id}', 'InternalCheckoutController@getPersonDetails');
        Route::get('get-billing-details', 'InternalCheckoutController@getBillingDetails');
        Route::get('get-payment-status', 'InternalCheckoutController@checkPaymentStatus');
        Route::get('get-order-payment-details/{id}', 'InternalCheckoutController@getOrderPaymentDetails');
        Route::post('clear-cart-session', 'InternalCheckoutController@clearCartSession');
        Route::post('web-refund', 'InternalCheckoutController@refundProcess');
        Route::post('order-cancel-update', 'InternalCheckoutController@cancelStatusUpdate')->middleware('checkPermission:api.flowerdrive.orders.update');
        Route::get('get-payment-failure-details/{id}', 'InternalCheckoutController@getPaymentFailureDetails');
        Route::get('get-flower-drive-contacts-list', 'InternalCheckoutController@getFlowerDriveContactList');
        Route::get('validate-fld-item-qty/{line_id}/{qty}', 'InternalCheckoutController@validateFldItemQuantity');
        Route::get('flower-drive-list', 'InternalCheckoutController@getFlowerDriveList')->middleware('checkPermission:api.flowerdrive.internalcheckout.create');
        Route::post('order-reset-cart/{id}', 'InternalCheckoutController@resetCartItems');
        Route::post('remove-cart-item', 'InternalCheckoutController@removeCartItem');
        Route::get('order-get-cart-items/{id}', 'InternalCheckoutController@getCartItems');
        Route::get('get-sites/{id}', 'InternalCheckoutController@getSites');
        Route::post('cart-save-tax', 'InternalCheckoutController@saveCartTax');
        Route::post('update-temp-cart-status/{cart_session_id}/{status}', 'InternalCheckoutController@updateTempCartStatus');
        Route::get('get-fld-line-quantity/{fld_line_id}', 'InternalCheckoutController@getTempCartFldLineQuantity');
        Route::post('get-site-deceases', 'InternalCheckoutController@getSiteWiseDeceases');
        Route::post('get-advance-search-deceases', 'InternalCheckoutController@getDeceasesForAdavanceSearch');
        Route::post('get-decease-view-record', 'InternalCheckoutController@getDeceaseDetails');
    });

    Route::group(['prefix' => '/flower-drive', 'middleware' => ['api', 'jwt.auth']], function () {
        Route::get('get-flower-drive-list', 'ManageFlowerDriveController@getFlowerDriveList')->middleware('checkPermission:api.flowerdrive.view');
        Route::get('get-flower-drive-location-list', 'OrderController@getFlowerDriveLocationList')->middleware('checkPermission:api.flowerdrive.view');
        Route::get('get-copy-flower-drive-list', 'ManageFlowerDriveController@getCopyOkFlowerDriveList')->middleware('checkPermission:api.flowerdrive.create');
        Route::get('check-flower-drive-market-maker-settings', 'ManageFlowerDriveController@checkMarketMakerSettings');
        Route::delete('/flower-drive-delete/{id}', 'ManageFlowerDriveController@destroy')->middleware('checkPermission:api.flowerdrive.delete');
        Route::post('create', 'ManageFlowerDriveController@createOrUpdateFlowerDriveDraftAPI')->middleware('checkPermission:api.flowerdrive.create');
        Route::post('copy', 'ManageFlowerDriveController@copyFlowerDrive')->middleware('checkPermission:api.flowerdrive.create');
        Route::post('activate', 'ManageFlowerDriveController@activateFlowerDrive');
        Route::post('publish', 'ManageFlowerDriveController@publishFlowerDrive');
        Route::post('generate-fld-pdf', 'ManageFlowerDriveController@generatePDF');
        Route::get('download-fld-pdf', 'ManageFlowerDriveController@downloadPDF');
        Route::post('update', 'ManageFlowerDriveController@createOrUpdateFlowerDriveDraftAPI')->middleware('checkPermission:api.flowerdrive.update');
        Route::post('complete', 'ManageFlowerDriveController@completeFlowerDrive');
        Route::get('get-flower-drive-by-id/{id}', 'ManageFlowerDriveController@getFlowerDriveById')->middleware('checkPermission:api.flowerdrive.view');
        Route::get('email-send-complete/{id}', 'ManageFlowerDriveController@IsFldEmailSentToAllContacts');
        Route::get('get-special-occations', 'ManageFlowerDriveController@getSpecialOccations');
        Route::get('get-contact-list-job-status', 'ManageFlowerDriveController@getContactListJobStatus');
        Route::post('contact-list-view/{id}/{is_logical}', 'ContactListController@allContactList');
        Route::post('contact-list-resend-email', 'ManageFlowerDriveController@resendContactListEmail');
        Route::post('get-packages', 'ManageFlowerDriveController@getPackagesForSelectedSites');
        Route::post('get-package-details', 'ManageFlowerDriveController@getPackageDetails');
        Route::get('get-flower-drive-contacts-list', 'ManageContactListController@getFlowerDriveContactList');
        Route::post('refresh-flower-drive-contacts-list', 'ManageFlowerDriveController@refreshContactList');
        // Route::post('send-fld-email', 'ManageFlowerDriveController@sendFldEmail');
        Route::post('send-fld-test-email', 'ManageFlowerDriveController@SendFLDTestEmail');
        Route::get('download-fld-csv-and-brochure/{flower_drive_id}', 'ManageFlowerDriveController@downloadCSVAndBrochure');
        Route::get('{fld_id}/{contact_id}/get-fld-for-interactions', 'ManageFlowerDriveController@getFlowerDriveByIdForInteraction');
        Route::get('get-org-type', 'ManageFlowerDriveController@getOrgType');
        Route::get('get-flower-drive-name/{fld_id}', 'ManageFlowerDriveController@getFlowerDriveName');
        Route::get('get-all-fld-paper-template-archieve', 'ManageFlowerDriveController@getAllFldPaperTemplateArchieve');
        Route::post('create-paper-template-chunk-list', 'ManageFlowerDriveController@createPaperTemplateChunkList');
        Route::get('get-paper-template-chunk-status', 'ManageFlowerDriveController@getPaperTemplateChunkStatus');
        Route::get('get-paper-template-archieve-chunk-status', 'ManageFlowerDriveController@getPaperTemplateArchieveChunkStatus');
        Route::post('create-paper-template-archieve-chunk-list', 'ManageFlowerDriveController@createPaperTemplateArchieveChunkList');
        Route::get('get-all-available-sections', 'ManageFlowerDriveController@getSectionsBySites');
        Route::get('get-flower-drive-type/{id}', 'ManageFlowerDriveController@getFlowerDriveType');
        Route::post('inactive', 'ManageFlowerDriveController@inactiveFloralStore');
        Route::get('download-paper-template-chunk', 'ManageFlowerDriveController@downloadPaperTemplateChunk');
        Route::post('save-fld-email-template', 'ManageFlowerDriveController@saveFldEmailTemplate');
        Route::post('delete-fld-email-template', 'ManageFlowerDriveController@deleteFldEmailTemplate');




        //Purchase order
        Route::get('show-all-orders', 'FlowerDrivePurchaseOrderController@ShowAllOrders')->middleware('checkPermission:api.flowerdrive.purchaseorder.view');
        Route::post('validate-release-purchase-orders', 'FlowerDrivePurchaseOrderController@validateReleasePo');
        Route::get('show-sites-for-tanant', 'FlowerDrivePurchaseOrderController@getSites');
        Route::get('show-all-sites', 'FlowerDrivePurchaseOrderController@getAllSitesForList');
        Route::get('show-flds', 'FlowerDrivePurchaseOrderController@getFlds');
        Route::get('get-all-flower-drives','FlowerDrivePurchaseOrderController@getAllFlowerDrives');
        Route::get('get-fld-po-details/{id}', 'FlowerDrivePurchaseOrderController@getFldPODetails')->middleware('checkPermission:api.flowerdrive.purchaseorder.view');
        Route::get('get-all-available-sites', 'FlowerDrivePurchaseOrderController@getAllAvailableSites');
        Route::get('generate-req-order-list', 'FlowerDrivePurchaseOrderController@generateOrderList')->middleware('checkPermission:api.flowerdrive.purchaseorder.create');
        Route::post('cancel-purchase-order', 'FlowerDrivePurchaseOrderController@cancelPurchaseOrder');
        Route::get('get-all-purchase-order-list', 'FlowerDrivePurchaseOrderController@index')->middleware('checkPermission:api.flowerdrive.purchaseorder.view');
        Route::get('get-preview-purchase-order-details/{id}', 'FlowerDrivePurchaseOrderController@getPreviewPurchaseOrderDetails');
        Route::post('save-purchase-orders','FlowerDrivePurchaseOrderController@savePurchaseOrders')->middleware('checkPermission:api.flowerdrive.purchaseorder.create');
        Route::get('download-purchase-order', 'FlowerDrivePurchaseOrderController@downloadPDF');
        Route::post('send-flower-drive-purchase-order-email', 'FlowerDrivePurchaseOrderController@sendEmail');
        Route::post('send-purchase-order-email', 'FlowerDrivePurchaseOrderController@sendPurchaseOrderEmail');
        Route::post('add-new-purchase-order-receipt', 'FlowerDrivePurchaseOrderController@savePurchaseOrderReceipt');
        Route::post('generate-purchase-order-pdf', 'FlowerDrivePurchaseOrderController@generatePurchaseOrderPDF');
        Route::get('download-purchase-order-pdf', 'FlowerDrivePurchaseOrderController@downloadPurchaseOrderPDF');
        Route::get('get-purchase-order-receipts/{id}', 'FlowerDrivePurchaseOrderController@getPurchaseOrderRecepts');
        Route::post('add-new-purchase-order-line-return', 'FlowerDrivePurchaseOrderController@savePurchaseOrderLineReturn');
        Route::post('generate-release-purchase-order-pdf', 'FlowerDrivePurchaseOrderController@generateReleasPurchaseOrderPDF');
        Route::post('save-generate-purchase-order-temp-data','FlowerDrivePurchaseOrderController@saveGenerateFldPOTempData');
        Route::get('get-all-generate-purchase-order-temp-data', 'FlowerDrivePurchaseOrderController@getGenerateFldPOTempData');
        Route::get('get-purchase-order-allocated-status', 'FlowerDrivePurchaseOrderController@getPurchaseOrderAllocatedStatus');
        Route::post('reset-temp-purchase-orders', 'FlowerDrivePurchaseOrderController@resetTempPurchaseOrders');
        Route::get('get-site-details-by-search-name/{field_name}/{siteName}', 'FlowerDrivePurchaseOrderController@getSiteDetailsBySearch');
        Route::get('get-all-purchase-order-suppliers','FlowerDrivePurchaseOrderController@getAllPurchaseOrderSuppliers');
        Route::get('get-purchase-order-schedule-details/{id}', 'FlowerDrivePurchaseOrderController@getPurchaseOrderScheduleDetails');

        //Service schedule
        Route::post('save-service-schedule-allocations', 'FlowerDrivePurchaseOrderController@saveServiceScheduleAllocations');
        Route::get('get-service-schedules/{id}', 'FlowerDrivePurchaseOrderController@getAllServiceSchedulesById');
        Route::get('service-schedule-allocation-confirm/{id}', 'FlowerDrivePurchaseOrderController@confirmServiceScheduleAllocation');
        Route::get('get-all-items-service-schedule', 'FlowerDrivePurchaseOrderController@getServiceSchedulesList')->middleware('checkPermission:api.items.servicechedule.view');
        Route::get('get-all-orders-service-schedule', 'FlowerDrivePurchaseOrderController@getServiceSchedulesList')->middleware('checkPermission:api.orders.servicechedule.view');
        Route::post('generate-service-schedule-pdf', 'FlowerDrivePurchaseOrderController@generatePDFServiceSchedule');
        Route::get('download-service-schedule-pdf', 'FlowerDrivePurchaseOrderController@downloadPDFServiceSchedule');
        Route::post('generate-service-schedule-allocation-pdf/{id}', 'FlowerDrivePurchaseOrderController@generatePDFServiceScheduleAllocation');
        Route::get('download-service-schedule-allocation-pdf', 'FlowerDrivePurchaseOrderController@downloadPDFServiceScheduleAllocation');
        Route::post('save-service-schedule-line-images', 'FlowerDrivePurchaseOrderController@saveServiceScheduleLineImages');
        Route::get('get-images-for-srv-line/{srv_line_id}', 'FlowerDrivePurchaseOrderController@getServiceScheduleLineImages');
        Route::post('generate-placement-schedule-pdf', 'FlowerDrivePurchaseOrderController@generatePlacementSchedulePDF');
        Route::get('download-placement-schedule-pdf', 'FlowerDrivePurchaseOrderController@downloadPlacementSchedulePDF');


        // Supplier
        Route::get('get-all-items-purchase-order-recived', 'FlowerDrivePurchaseOrderController@getPoOrderReciveData')->middleware('checkPermission:api.items.purchaseordersreceived.view');
        Route::get('get-all-orders-purchase-order-recived', 'FlowerDrivePurchaseOrderController@getPoOrderReciveData')->middleware('checkPermission:api.orders.purchaseordersreceived.view');
        Route::get('get-all-po-number-list', 'FlowerDrivePurchaseOrderController@getPoNumberList');

        Route::post('purchase-order-recive-data-pdf', 'FlowerDrivePurchaseOrderController@generatePoReciveDataPDF');
        Route::get('purchase-order-recive-data-pdf', 'FlowerDrivePurchaseOrderController@downloadPoReciveDataPDF');
        Route::get('get-preview-received-purchase-order-details/{id}', 'FlowerDrivePurchaseOrderController@getPreviewReceivedPurchaseOrderDetails');

        Route::get('get-all-fld-paper-template-list', 'ManageFlowerDriveController@getAllFldPaperTemplateList');
        Route::get('get-contact-list-fld-template', 'ManageFlowerDriveController@getContactListForTemplateModal');

        Route::group(['prefix' => 'configurations'], function () {
            Route::group(['prefix' => 'packages'], function () {
                Route::get('list', 'ManagePackagesController@index')->middleware('checkPermission:api.flowerdrive.configurations.packages.view');
                Route::post('save-package', 'ManagePackagesController@savePackage')->middleware('checkPermission:api.flowerdrive.configurations.packages.create');
                Route::get('get-items-for-package', 'ManagePackagesController@geItemsForPackage');
                Route::post('check-item-and-pricing-validation', 'ManagePackagesController@validateItemsAndPricing');
                Route::post('{package_id}/add', 'ManagePackagesController@packageItemStore');
                Route::post('{id}/delete', 'ManagePackagesController@destroy')->middleware('checkPermission:api.flowerdrive.configurations.packages.delete');
                Route::get('get-package-by-id/{id}', 'ManagePackagesController@getPackageById')->middleware('checkPermission:api.flowerdrive.configurations.packages.view');
                Route::get('get-package-details-for-edit/{id}', 'ManagePackagesController@getPackageDetailsForEdit')->middleware('checkPermission:api.flowerdrive.configurations.packages.update');
                Route::get('get-added-items-for-package/{id}', 'ManagePackagesController@getAlreadyAddItemsForPackage');
                Route::post('{id}/update', 'ManagePackagesController@updatePackage')->middleware('checkPermission:api.flowerdrive.configurations.packages.update');
                Route::post('{id}/delete-item', 'ManagePackagesController@deleteItem');
                Route::get('get-package-item-price-list/{item_id}', 'ManagePackagesController@getPackageItemRelatedPriceList');
            });
        });

        //Get purchase order by consumer order for roses only
        Route::get('get-po-details-for-consumer-orders/{fld_id}', 'ManageFlowerDriveController@getPoDetailsForConsumerOrders');
        Route::get('get-flower-drive-list-for-consumer-po', 'ManageFlowerDriveController@getFlowerDriveListForConsumerPo');
        Route::get('download-flower-drive-list-for-consumer-po-csv', 'ManageFlowerDriveController@downloadPoByConsumerOrderCSV');
        Route::get('check-supplier-permission', 'ManageFlowerDriveController@checkSupplierPermission');
        Route::get('marketMaker-permission', 'ManageFlowerDriveController@checkMarketmakerSettingsForAddNewSite');

        // FOR create-contact-details-by-admin
        Route::post('create-contact-details-by-admin/{id}', 'ManageFlowerDriveController@createContactDetailsByAdmin')->middleware('role:super_admin|user');


    });
    // API for invoice
    Route::group(['prefix' => 'invoice'], function () {
        Route::get('', 'InvoiceController@getAllInvoices')->middleware('checkPermission:api.flowerdrive.invoices.view');
        Route::post('generate-pdf', 'InvoiceController@generatePDF');
        Route::get('download-pdf', 'InvoiceController@downloadPDF');
    });
    // End of API for invoice
});

Route::group(['prefix' => 'v1/external'], function () {
        //ByondMarket
        Route::post('generate-fld-invoice', 'OrderController@generateFldInvoiceExt');
        Route::post('send-fld-consumer-invoice-email', 'OrderController@sendFldConsumerInvoiceEmail');
        Route::post('send-fld-consumer-refund-email', 'OrderController@sendFldConsumerRefundEmail');
        Route::post('generate-refund-confirmation', 'OrderController@generateRefundConfirmation');
        Route::post('send-refund-confirmation-email', 'OrderController@sendRefundConfirmationEmail');
        Route::post('download-fld-payment-receipt', 'OrderController@downloadFldPaymentReceipt');
        Route::post('send-fld-email-with-payment-receipt', 'OrderController@sendFldEmailWithPaymentReceipt');
        Route::post('get-site-logo', 'OrderController@getSiteLogoExternal');
        Route::post('send-failed-email', 'OrderController@sendFailedEmail');



    });

Route::group(['prefix' => 'v1/order'], function () {

    Route::group(['middleware' => 'cors'], function() {
        Route::post('send-order-cancel-email', 'OrderController@sendOrderCancelEmail');
        Route::get('get-reference/{type}/{tenant_id}', 'InternalCheckoutController@getReferenceNumber');
        Route::get('get-flower-drive-order-list', 'OrderController@getFlowerDriveOrderDetails')->middleware('checkPermission:api.flowerdrive.orders.view');
        Route::post('generate-pdf-for-flower-drive-orders', 'OrderController@generatePdfForFlowerDriveList');
        Route::get('get-fld-order-list/{id}', 'OrderController@getFldOrderDetailsForFldId');
        Route::post('generate-pdf-for-flower-drive-orders-by-fld-id/{id}', 'OrderController@generatePdfForFlowerDriveListFilterByFldId');
        Route::get('get-fld-purchase-order-list/{id}', 'OrderController@getFldOrderDetailsForFldPOId');
        Route::post('generate-pdf-for-flower-drive-orders-by-fld-PO-id/{id}', 'OrderController@generatePdfForFlowerDriveListFilterByFldPOId');

    });

    Route::get('download-fld-order-list-pdf', 'OrderController@downloadFlowerDriveOrderListPDF');
    Route::get('get-flower-drive-order-list/{id}', 'OrderController@getSingleFlowerDriveOrderDetails');
});


