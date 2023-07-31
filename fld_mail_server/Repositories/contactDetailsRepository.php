<?php


namespace App\Modules\FlowerDrive\Repositories;


use App\CartSession;
use App\Modules\FlowerDrive\Contracts\FLDBMContactRepositoryInterface;
use App\Repositories\MainRepository;
use Barryvdh\Snappy\Facades\SnappyPdf as PDF;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;
use App\FlowerDrive;
use App\FlowerDriveByondMarketContact;
use App\FlowerDriveLine;
use App\Modules\Emailtemplate\Contracts\EmailTemplateRepositoryInterface;

class FLDBMContactRepository extends MainRepository implements FLDBMContactRepositoryInterface
{

    private $emailTemplateRepository;
    private $manageFlowerDriveRepository;

    public function __construct(EmailTemplateRepositoryInterface $emailTemplateRepository, ManageFlowerDriveRepository $manageFlowerDriveRepository)
    {
        $this->emailTemplateRepository = $emailTemplateRepository;
        $this->manageFlowerDriveRepository = $manageFlowerDriveRepository;
    }
    function model()
    {
        return 'App\FlowerDriveByondMarketContact';
    }

    public function getFlowerDriveContactPerson($fldBMContactId)
    {
        return FlowerDriveByondMarketContact::with('fldContacts')
             ->where('id', $fldBMContactId)
             ->first();
    }

    public function getFloralProgramContactByChunkId($floral_program_paper_template_chunk)
    {
        return FlowerDriveByondMarketContact::select('id', 'fld_id', 'terr_id', 'contact_email', 'email_template_id', 'email_sent_count', 'fld_contact_id')
            ->with(['Territory' => function ($q) {
                $q->select('terr_name', 'terr_id', 'contact_email');
            }, 'fldContacts' => function ($q) {
                $q->select('id', 'contact_id')->with('person.relationships.deceasedRelatedPerson');
            }])
            ->where('fld_paper_template_pdf_id', $floral_program_paper_template_chunk->fld_paper_template_pdf_id)->get();
    }

    public function generateFloralProgramPaperTemplates($floral_program_id, $contacts)
    {
        try {
            $details        = [];
            $template       = "";
            $floral_program = FlowerDrive::query()->where('fld_id', $floral_program_id)->first();
            $template_details =  $this->emailTemplateRepository->getTemplateDetailsById($floral_program->paper_template_id);
            if (sizeof($template_details) == 0)
                throw new \Exception("Template Not Found");
            $template_data = [];
            $template_html = html_entity_decode($template_details[0]['template_details']);

            if ($template_details[0]['template_type'] == 'PAPER') {

                $item_details = [];
                $templateDetails = $this->emailTemplateRepository->getEmailcontentforSendEmail($floral_program_id);
                $flowerDriveLine = new FlowerDriveLine();

                if (isset($templateDetails[0]->lines) && $templateDetails[0]->lines != null) {
                    $item_details = [];
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
                        foreach ($lines['lineItemPrice'] as $lineItemPrice) {
                            if ($lineItemPrice->fld_line_id == $lines->fld_line_id) {
                                $currency = $lineItemPrice->currency;
                                $arr_key = $lineItemPrice->fld_line_id;

                                $linePrices[] = $lineItemPrice->total_extended_price;
                                $linePrices = collect($linePrices);
                                $minPrice = $linePrices->min();
                                $maxPrice = $linePrices->max();

                                if ($minPrice == $maxPrice) {
                                    $itemPrice = $currency . " " . $minPrice;
                                } else {
                                    $itemPrice = $currency . " " . $minPrice . " - " . $currency . " " . $maxPrice;
                                }

                                $item_details[$arr_key] = [
                                    'item_name' => $lines->line_name ? $lines->line_name : '',
                                    'item_description' => $lines->line_desc ? $lines->line_desc : '',
                                    'item_price' => $itemPrice,
                                    'item_image' => isset($imagePath) ? $imagePath : null,
                                ];
                            }
                        }
                    }
                }

                $text['item_images'] = $item_details;

                foreach ($contacts as $contact) {
                    if (isset($templateDetails) && $templateDetails != null) {
                        //QR code
                        $landingPage = "";
                        $landingPage = $templateDetails[0]->FlowerDriveSite[0]['web_page'] ? $templateDetails[0]->FlowerDriveSite[0]['web_page'] : "";
                        $landingPage = appendSingleParameterToWebURL($landingPage, 'c', $contact['id']);
                        $text['qr_code_path'] = "https://chart.googleapis.com/chart?chs=400x400&cht=qr&chl=" . $landingPage;
                        $text['first_name'] = isset($contact['fldContacts']['person']['first_name']) ? $contact['fldContacts']['person']['first_name'] : "";
                        $text['last_name'] = isset($contact['fldContacts']['person']['last_name']) ? $contact['fldContacts']['person']['last_name'] : "";
                        $text['address_1'] = isset($contact['fldContacts']['person']['primaryAddress']['address1']) ? $contact['fldContacts']['person']['primaryAddress']['address1'] : "";
                        $text['address_2'] = isset($contact['fldContacts']['person']['primaryAddress']['address2']) ? $contact['fldContacts']['person']['primaryAddress']['address2'] : "";
                        $text['suburb_town'] = isset($contact['fldContacts']['person']['primaryAddress']['rk_state']) ? $contact['fldContacts']['person']['primaryAddress']['rk_region'] : "";
                        $text['state'] = isset($contact['fldContacts']['person']['primaryAddress']['rk_state']) ? $contact['fldContacts']['person']['primaryAddress']['rk_state'] : "";
                        $text['country'] = isset($contact['fldContacts']['person']['primaryAddress']['rk_country']) ? $contact['fldContacts']['person']['primaryAddress']['rk_country'] : "";
                        $text['post_code'] = isset($contact['fldContacts']['person']['primaryAddress']['rk_postal_code_id']) ? $contact['fldContacts']['person']['primaryAddress']['rk_postal_code_id'] : "";;;
                        $text['deceased_first_name'] = isset($contact['fldContacts']['person']['relationships'][0]['relatedPerson']['first_name']) ? $contact['fldContacts']['person']['relationships'][0]['relatedPerson']['first_name'] : "";
                        $text['deceased_last_name'] = isset($contact['fldContacts']['person']['relationships'][0]['relatedPerson']['last_name']) ? $contact['fldContacts']['person']['relationships'][0]['relatedPerson']['last_name'] : "";

                        $text['flower_drive_name'] = $templateDetails[0] && $templateDetails[0]->fld_name ? $templateDetails[0]->fld_name : '';
                        $text['flower_drive_description'] = $templateDetails[0] && $templateDetails[0]->fld_desc ? $templateDetails[0]->fld_desc : '';
                        $text['order_by_date'] = $templateDetails[0] && $templateDetails[0]->order_by_date ? date('d-F-Y', strtotime($templateDetails[0]->order_by_date)) : '';
                        $text['cancel_by_date'] = $templateDetails[0] && $templateDetails[0]->cancel_by_date ? date('d-F-Y', strtotime($templateDetails[0]->cancel_by_date)) : '';
                        $text['special_occasion'] = $templateDetails[0] && $templateDetails[0]->specialOccassion && $templateDetails[0]->specialOccassion->occ_name ? $templateDetails[0]->specialOccassion->occ_name : '';
                        $text['special_occasion_date'] = $templateDetails[0] && $templateDetails[0]->specialOccassion && $templateDetails[0]->specialOccassion->occ_date ? formatDateTime($templateDetails[0]->specialOccassion->occ_date, 'Y-m-d') : '';
                        $text['email'] = $contact['fldContacts']['person']['primaryEmail']['email'] ? $contact['fldContacts']['person']['primaryEmail']['email'] : '';
                    }
                    $template_data = $text;
                    $template .= $template_html && $template_data ? $this->emailTemplateRepository->createPaperTemplate($template_data, $template_html, true) : [];
                    $template .= "<div class='hide-print' style='background-color:#ccc; height:5px; width: 100%; margin-top: 20px; margin-bottom:20px;'></div>";
                    $template .= "<div style='page-break-before: always;'></div>";
                }
            }

            $details = [
                'template' => $template,
                'templateData' => $template_data,
            ];
            return $details;
        } catch (\Exception $e) {
            Log::error('generateFloralProgramPaperTemplates : ' . $e->getMessage());
            throw $e;
        }
    }

    public function moveFloralProgramPaperTemplatesToStorage($floral_program_paper_template_chunk, $details, $file_location, $pdf_fileName)
    {
        try {
            $data = null;
            $divText = $details['template'];

            $template_size = 'A4';
            $template_orientation = 'portrait';
            $html = $divText;
            $updated_html = $html;
            $updated_html = $html;
            $html = str_replace("\n", '', $html);
            $html = str_replace("\r", '', $html);
            $html = str_replace('<!DOCTYPE html PUBLIC "-//W3C//DTD HTML 4.0 Transitional//EN" "http://www.w3.org/TR/REC-html40/loose.dtd">', '', $html);
            $html = str_replace('<html><body>', '', $html);
            $html = str_replace('</body></html>', '', $html);
            $updated_html = $html;
            $data = [
                'divText' => $updated_html,
            ];

            $pdf = PDF::loadView('emailtemplate::manage_email_template_preview_pdf', $data);
            $pdf->setOption('margin-left', '0');
            $pdf->setOption('margin-right', '0');
            $pdf->setOption('margin-top', '0');
            $pdf->setOption('margin-bottom', '0');
            $file_path = $file_location . '/' . $pdf_fileName;
            $pdf->setPaper($template_size, $template_orientation)->save(public_path($file_path));

            $file_size = 0;
            $local_uplodad_file = fopen(public_path($file_path), 'r');
            if (env('S3_ENABLED')) {
                if (!Storage::disk('s3')->exists($file_path))
                    Storage::disk('s3')->makeDirectory($file_location);
                // save the zip file to s3 bucket storage
                Storage::disk('s3')->put(
                    $file_path,
                    $local_uplodad_file,
                    ['ACL' => env('S3_BUCKET_ACL'), 'CacheControl' => env('S3_BUCKET_CACHE_CONTROL')]
                );
                $file_size = Storage::disk('s3')->size($file_path);
            } else {
                if (!Storage::disk('local')->exists($file_path))
                    Storage::disk('local')->makeDirectory($file_location);
                // save the zip file to local storage
                Storage::disk('local')->put($file_path, $local_uplodad_file);
                $file_size = Storage::disk('local')->size($file_path);
            }
            $floral_program_paper_template_chunk->file_size = round($file_size / 1000);
            $floral_program_paper_template_chunk->save();
            File::deleteDirectory(public_path('floral_program'));
        } catch (\Exception $e) {
            Log::error('moveFloralProgramPaperTemplatesToStorage : ' . $e->getMessage());
            throw $e;
        }
        return true;
    }

    function formatSizeUnits($bytes)
    {
        return number_format($bytes / 1000000000, 2);
    }
}
