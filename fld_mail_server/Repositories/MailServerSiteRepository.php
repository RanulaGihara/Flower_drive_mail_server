<?php


namespace App\Modules\FlowerDrive\Repositories;



use App\Modules\FlowerDrive\Contracts\FlowerDriveSiteRepositoryInterface;
use App\Repositories\MainRepository;

class FlowerDriveSiteRepository extends MainRepository implements FlowerDriveSiteRepositoryInterface
{

    function model()
    {
        return 'App\Site';
    }

    /**
 * New function for sending flower drive emails for contacts
 *
 * @param int $flowerDriveId
 * @param string $organizationType
 * @param array|null $resendContactIds
 * @return void
 */
public function sendFlowerDriveEmailsForContacts($flowerDriveId, $organizationType, $resendContactIds = null, $options = null, $contactIdsListForEmails = [], $executedChunkedJobCount = null, $chunksCount = null, $selectedEmailTemplate = null, $userEmailAddress = null, $userName = null, $userId = null)
{
    $organizationId = $this->makeModel()->where('fld_id', $flowerDriveId)->select('org_id')->first()->org_id;
    $chunkIndex = 0;
    $contactStatus = isset($selectedEmailTemplate->contact_status) ? $selectedEmailTemplate->contact_status : 'NOT_SENT';

    try {
        $templateDetails = $this->emailTemplateRepo->getEmailContentForSendEmail($flowerDriveId);

        $flowerDriveLine = new FlowerDriveLine();

        if (isset($templateDetails) && $templateDetails != null && $templateDetails[0]) {
            // Rest of the code...

            if ($contactListChunkCount > 0) {
                // chunk contact list and send for flower drive send email job
                $contactList->chunk($chunkSize, function ($contactList) use ($templateHtml, $webPageDetails, $emailBody, $emailSubject, &$chunkIndex, $organizationId, $organizationType, $flowerDriveId, &$executedChunkedJobCount, $chunksCount, $chunksCountForEmails, &$executedChunkedJobCountForEmails, $isContactListUpdated, $userEmailAddress, $userName, $selectedEmailTemplate, $userId) {
                    // Rest of the code...
                });
            } else {
                $this->updateSelectedColumnsForFlowerDrive(['con_list_job' => 'COMPLETED'], $flowerDriveId);
                // send confirmation mail
                if ($userEmailAddress !== null) {
                    $flowerDriveName = FlowerDrive::select('fld_name')->where('fld_id', $this->fldId)->pluck('fld_name')->first();
                    $email = new FlowerDriveEmailConfirmation($flowerDriveName, $userName);
                    Mail::to($userEmailAddress)->send($email);
                }
            }
        }
    } catch (\Exception $ex) {
        Log::error($ex->getMessage());
    }
}

}
