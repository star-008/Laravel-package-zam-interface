<?php

namespace ZamApps\ZamInterface\FileImport;

use ZamApps\ZamInterface\Services\AWS\SMS\AwsSmsService;
use Illuminate\Support\Facades\Mail;
use Maatwebsite\Excel\Facades\Excel;

class ValidationFailureProcessor
{
    private $awsSmsService;
    private $smsRecipients = [];

    public function __construct()
    {
        $this->awsSmsService = new AwsSmsService();
    }

    /**
     * @param $fails
     * @param $notification_name
     * @param $filename
     *
     * @return array
     */
    function processValidationFailures($fails, $notification_name, $filename, $movedFile)
    {

        $attachments = [];
        foreach ($fails as $comparison => $fail) {
            $attachments[] = $this->processSingleFail($fail);
        }

        // if empty($attachments), why are we here? get out

        $subscriptions = $this->getSubscribers($notification_name);
        $notification = Notifications::where('notification', '=', $notification_name)->first();

        // attach files to email - use MAIL_PRETEND=true on local, nothing needed on other servers
        $data =
            [
                'attachments' => $attachments,
                'subscriptions' => $subscriptions,
                'filename' => $filename,
                'orig_file' => $movedFile,
                'subject' => $notification->subject,
            ];

        // Get the reprocessing URL
        $reprocess = $this->getUrlFromNotificationName($notification_name);

        if ($reprocess) {
            $messageBody =
                $notification->message_body
                . ' ( '
                . $filename
                . ' is also attached to this email. Please make the appropriate change to this file, then upload it again at: '
                . url($reprocess)
                . ' )';
        } else {
            $messageBody = $notification->message_body;
        }

        Mail::send('emails.notifications.filetransfervalidation', [
            'message_body' => $messageBody,
        ], function ($message) use ($data) {

            $message->to('admin@vinoez.com', 'Admin')->subject($data['subject']);

            foreach ($data['subscriptions'] as $subscription) {
                $message->cc($subscription['email'], $subscription['username']);
            }

            foreach ($data['attachments'] as $attachment) {
                $message->attach(storage_path('filetransfervalidations') . '/' . $attachment);
            }

            $message->attach($data['orig_file']);
        });
        $this->sendBulkSMS($data['subject']);

        $attachments[] = $movedFile;

        // TODO: We are returning this - but we don't accept it on the other side... (?)
        return $attachments;
    }

    /**
     * Send Notifications on successful completion of file import process.
     *
     * @param string $notification_name
     * @param string $filename
     */
    function processValidationSuccess($notification_name, $filename)
    {

        $subscriptions = $this->getSubscribers($notification_name);
        $notification = Notifications::where('notification', '=', $notification_name)->first();

        // attach files to email - use MAIL_PRETEND=true on local, nothing needed on other servers

        $data = ['subscriptions' => $subscriptions, 'filename' => $filename, 'subject' => $notification->subject,];

        Mail::send(
            'emails.notifications.filetransfervalidation',
            ['message_body' => $notification->message_body],
            function ($message) use ($data) {

                $message->to('admin@vinoez.com', 'Admin')->subject($data['subject']);

                foreach ($data['subscriptions'] as $subscription) {
                    $message->cc($subscription['email'], $subscription['username']);
                }
            }
        );
        $this->sendBulkSMS($data['subject']);
    }

    /**
     * @param       $notification_name
     * @param       $filename
     * @param       $movedFile
     * @param null $responseMsg
     * @param array $responseErrors
     *
     * @return array
     */
    function processStagingFailure($notification_name, $filename, $movedFile, $responseMsg = null, $responseErrors = [])
    {

        $attachments = [$movedFile];

        $subscriptions = $this->getSubscribers($notification_name);
        $notification = Notifications::where('notification', '=', $notification_name)->first();

        // attach files to email - use MAIL_PRETEND=true on local, nothing needed on other servers
        $data =
            [
                'subscriptions' => $subscriptions,
                'filename' => $filename,
                'orig_file' => $movedFile,
                'subject' => $notification->subject,
            ];

        // Get any error messages
        $errorMsgs = [];
        if (count($responseErrors) > 0) {
            foreach ($responseErrors as $error) {
                $errorMsgs[] = json_encode($error);
            }
        }

        $messageBody =
            $notification->message_body
            . '<br>(The import file, "'
            . $filename
            . '" is also attached to this email for your reference.)'

            . '<br>The issues encountered were:<br>'
            . $responseMsg;

        if ($errorMsgs > 0) {
            $messageBody .= '<br><br><strong>ERRORS:</strong><ul>';
            foreach ($errorMsgs as $errMsg) {
                $messageBody .= '<li>' . $errMsg . '</li>';
            }
            $messageBody .= '</ul>';
        }
        Mail::send('emails.notifications.filetransfervalidation', [
            'message_body' => $messageBody,
        ], function ($message) use ($data) {

            $message->to('admin@vinoez.com', 'Admin')->subject($data['subject']);

            foreach ($data['subscriptions'] as $subscription) {
                $message->cc($subscription['email'], $subscription['username']);
            }

            $message->attach($data['orig_file']);
        });
        $this->sendBulkSMS($data['subject']);

        // TODO: We are returning this - but we don't accept it on the other side... (?)
        return $attachments;
    }

    /**
     * @param       $notification_name
     * @param       $filename
     * @param       $movedFile
     * @param null $responseMsg
     * @param array $responseErrors
     *
     * @return array
     */
    function processLoadFailure($notification_name, $filename, $movedFile, $responseMsg = null, $responseErrors = [])
    {

        $attachments = [$movedFile];

        $subscriptions = $this->getSubscribers($notification_name);
        $notification = Notifications::where('notification', '=', $notification_name)->first();

        // attach files to email - use MAIL_PRETEND=true on local, nothing needed on other servers
        $data =
            [
                'subscriptions' => $subscriptions,
                'filename' => $filename,
                'orig_file' => $movedFile,
                'subject' => $notification->subject,
            ];

        // Get any error messages
        $errorMsgs = [];
        if (count($responseErrors) > 0) {
            foreach ($responseErrors as $error) {
                $errorMsgs[] = json_encode($error);
            }
        }

        $messageBody =
            $notification->message_body
            . '<br>(The import file, "'
            . $filename
            . '" is also attached to this email for your reference.)';
        // For future inclusion - will not be used right now
        //            . '<br>The results of the data load were:<br>'
        //            . $responseMsg

        //        if ($errorMsgs > 0) {
        //            $messageBody .= '<br><br><strong>ERRORS:</strong><ul>';
        //            foreach ($errorMsgs as $errMsg) {
        //                $messageBody .= '<li>' . $errMsg . '</li>';
        //            }
        //            $messageBody .= '</ul>';
        //        }

        Mail::send('emails.notifications.filetransfervalidation', [
            'message_body' => $messageBody,
        ], function ($message) use ($data) {

            $message->to('admin@vinoez.com', 'Admin')->subject($data['subject']);

            foreach ($data['subscriptions'] as $subscription) {
                $message->cc($subscription['email'], $subscription['username']);
            }

            $message->attach($data['orig_file']);
        });
        $this->sendBulkSMS($data['subject']);

        // TODO: We are returning this - but we don't accept it on the other side... (?)
        return $attachments;
    }

    /**
     * @param $fail
     *
     * @return string
     */
    function processSingleFail($fail)
    {

        // Need to convert $fail from an object to an array so that the Excel package can
        // subsequently create the sheet from an array because that what it needs
        $fail = json_decode(json_encode($fail), true);
        $filename = $fail['dataSet'] . "_" . $fail['comparison'] . "_" . date("Y-m-d-H-i-s");
        $format = 'xls';

        Excel::create($filename, function ($excel) use ($fail) {

            $excel->sheet($fail['comparison'], function ($sheet) use ($fail) {

                $sheet->fromArray($fail['data'], 'N/A', 'A1', true);
            });
        })
            ->store($format, storage_path('filetransfervalidations'), true);

        return $filename . '.' . $format;
    }

    /**
     * Send Mail when a file is not connected to a FileImport request.
     *
     * @param string $notification_name
     * @param string $dataSet
     */
    function processMissingFile($notification_name, $dataSet)
    {

        $subscriptions = $this->getSubscribers($notification_name);
        $notification = Notifications::where('notification', '=', $notification_name)->first();

        $data =
            [
                'subscriptions' => $subscriptions,
                'attempted' => $dataSet,
                'subject' => $notification->subject,
            ];

        $messageBody =
            $notification->message_body
            . '<br>(The attempted import was for the "'
            . $data['attempted']
            . '" file.)';

        Mail::send('emails.notifications.filetransfervalidation', [
            'message_body' => $messageBody,
        ], function ($message) use ($data) {

            $message->to('admin@vinoez.com', 'Admin')->subject($data['subject']);

            foreach ($data['subscriptions'] as $subscription) {
                $message->cc($subscription['email'], $subscription['username']);
            }
        });
        $this->sendBulkSMS($data['subject']);

    }

    /**
     * @param $notification_name
     *
     * @return array
     */
    public function getSubscribers($notification_name)
    {

        $notification = Notifications::where('notification', '=', $notification_name)->first();

        $subscribers = [];

        if (empty($notification)) {
            return $subscribers;
        }

        foreach ($notification->subscribers as $u) {
            $subscribers[$u->user_info['email']] =
                ['email' => $u->user_info['email'], 'username' => $u->user_info['username']];
            //separating the sms recipients
            if ($u->send_via_sms) {
                $this->smsRecipients[] = $u->user;
            }
        };

        return $subscribers;
    }

    /**
     * @param $notification_name )
     *
     * @return bool|string
     */
    public function getUrlFromNotificationName($notification_name)
    {

        switch ($notification_name) {
            case 'barrelPlanningFileImportValidation':
                $slug = 'update-barrel-planning-vessel-data';
                break;
            case 'barrelDataFileImportValidation':
                $slug = 'barrel-data';
                break;
            case 'barrelGroupFileImportValidation':
                $slug = 'barrel-group';
                break;
            case 'bottlingPlanFileImportValidation':
                $slug = 'bottling-plan';
                break;
            case 'harvestSchedulerBlockFileImportValidation':
                $slug = 'harvest-scheduler-block';
                break;
            case 'harvestSchedulerDataFileImportValidation':
                $slug = 'harvest-scheduler-data';
                break;
            case 'labQAHistoryFileImportValidation':
                $slug = 'lab-qa-history';
                break;
            case 'masterInventoryDataFileImportValidation':
                $slug = 'master-inventory-data';
                break;
            case 'masterTankFileImportValidation':
                $slug = 'master-tank';
                break;
            case 'operationsDataFileImportValidation':
                $slug = 'operations-data';
                break;
            case 'tankInventoryFileImportValidation':
                $slug = 'tank-inventory';
                break;
            case 'blockStylesFileImportValidation':
                $slug = 'block-styles';
                break;
            case 'harvestQualityAgCodeFileImportValidation':
                $slug = 'harvest-quality-ag-code';
                break;
            case 'harvestWeighTagAgCodeFileImportValidation':
                $slug = 'harvest-weigh-tag-ag-code';
                break;
            case 'harvestBlockAgCodeFileImportValidation':
                $slug = 'harvest-scheduler-block-ag-code';
                break;
            case 'harvestAllocationAgCodeFileImportValidation':
                $slug = 'harvest-allocations-ag-code';
                break;
            default:
                return false;
        }

        return 'file-transfer/' . $slug;
    }

    private function sendBulkSMS($message)
    {
        $this->awsSmsService->sendBulkSMS($this->smsRecipients, $message, true);
        //clear sms recipients every time SMSs are sent
        $this->smsRecipients = [];
    }
}
