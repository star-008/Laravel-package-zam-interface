<?php

namespace ZamApps\ZamInterface\FileImport;

use App\JobLog;
use App\Jobs\Job;
use ZamApps\ZamInterface\Services\AWS\SMS\AwsSmsService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;

if (version_compare(PHP_VERSION, '7.2.0', '>=')) {
    // Ignores notices and reports everything from warning level up
    error_reporting(E_ALL ^ E_WARNING);
}

class ProcessFileImport extends Job implements ShouldQueue
{

    use InteractsWithQueue;

    protected $logic;
    protected $noAsync;
    protected $waitTime;
    protected $jobType;
    protected $filename;
    protected $table;
    protected $comparisons;
    protected $dataSet;
    protected $readable;
    protected $failedNotification;
    protected $successNotification;
    protected $controller;
    protected $awsSmsService;
    protected $smsRecipients = [];

    /**
     * ProcessFileImport constructor.
     *
     * @param array   $noAsync
     * @param integer $waitTime
     * @param string  $jobType
     * @param string  $filename
     * @param string  $table
     * @param array   $comparisons
     * @param string  $dataSet
     * @param string  $readable
     * @param string  $failedNotification
     * @param string  $successNotification
     */
    public function __construct(
        FileImportLogic $logic,
        $noAsync,
        $waitTime,
        $jobType,
        $filename,
        $table,
        $comparisons,
        $dataSet,
        $readable,
        $failedNotification,
        $successNotification,
        $controller
    ) {

        $this->logic               = $logic;
        $this->noAsync             = $noAsync;
        $this->waitTime            = $waitTime;
        $this->jobType             = $jobType;
        $this->filename            = $filename;
        $this->table               = $table;
        $this->comparisons         = $comparisons;
        $this->dataSet             = $dataSet;
        $this->readable            = $readable;
        $this->failedNotification  = $failedNotification;
        $this->successNotification = $successNotification;
        $this->controller          = $controller;
        $this->awsSmsService       = new AwsSmsService();
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {

        // Find out if any conflicting imports are currently running
        $runningJobs = JobLog::isRunning($this->noAsync)->get();
        $jobsAreRunning = $runningJobs && is_countable($runningJobs) && count($runningJobs) > 0;

        // If we have tried this three times already, something is amiss... notify relevant stakeholders
        if ($this->attempts() > 3) {
            if ($jobsAreRunning){
                $jobIds = '(';
                foreach($runningJobs as $runningJob){
                    $jobIds .= $runningJob->id . ',';
                }
                $jobIds = rtrim($jobIds, ',');
                $jobIds .= ')';
                $messageDetails = 'This usually means that conflicting files '
                                  . $jobIds
                                  . ' are being processed simultaneously. Please wait at least '
                                  . $this->waitTime / 60
                                  . ' minutes, then attempt the import again. If you receive this email a second time, please contact support.';
            } else{
                $otherError = ' This error appears to be unrelated to conflicting file import processes, so please contact support for assistance.';
                $messageDetails = $otherError;
            }

            // Send an alert to interested parties
            $subscribers = $this->getSubscribers($this->failedNotification);
            $messageBody =
                'Three attempts have been made to process the recently submitted file for the '
                . $this->filename
                . ' file import on the processing queue, but it has been unable to start.'
                . $messageDetails;

            $data =
                [
                    'subscribed' => $subscribers,
                    'filename'   => $this->filename,
                    'subject'    => 'Recent ' . $this->readable . ' File Import Removed From Queue',
                ];
            Mail::send('emails.notifications.filetransfervalidation', [
                'message_body' => $messageBody,
            ], function ($message) use ($data) {

                $message->to('admin@vinoez.com', 'Admin')->subject($data['subject']);

                foreach ($data['subscribed'] as $subscription) {
                    $message->cc($subscription['email'], $subscription['username']);
                }
            });

            $this->sendBulkSMS($data['subject']);

        }

        if ($jobsAreRunning) {
            // Conflicting imports are currently running - put this back on the queue and attempt to process it later
            $this->release($this->waitTime);
        } else {
            // Get the actual file for the runFileImport process...
            $movedFile = "storage/uploads/" . $this->filename;

            // Run the Job through the Queue
            try {
                $this->logic->runFileImport(
                    true,
                    false,
                    $this->jobType,
                    $this->filename,
                    $movedFile,
                    $this->table,
                    $this->comparisons,
                    $this->dataSet,
                    $this->readable,
                    $this->failedNotification,
                    $this->successNotification,
                    $this->controller
                );
            } catch (\Exception $e) {
                $message = $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine();
                Log::info('runFileImport returned an Exception: ' . $message);
            }
        }

    }

    private function getSubscribers($notification_name)
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
        }

        return $subscribers;
    }
    private function sendBulkSMS($message)
    {
        $this->awsSmsService->sendBulkSMS($this->smsRecipients, $message, true);
        //clear sms recipients every time SMSs are sent
        $this->smsRecipients = [];
    }
}
