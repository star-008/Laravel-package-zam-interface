<?php

namespace ZamApps\ZamInterface\Controllers;

use App\JobLog;
use ZamApps\ZamInterface\FileImport\ProcessFileImport;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use ZamApps\ZamInterface\Services\FileImportService;
use Illuminate\Support\Facades\Request;
use ZamApps\ZamInterface\FileImport\FileImportLogic;
use ZamApps\ZamInterface\FileImport\ValidationFailureProcessor;

class BaseFileImportController extends Controller
{

    /**
     * @param array    $noAsync             - The array of staging table names that would conflict with this process
     * @param integer  $waitTime            - The number of seconds that should pass before attempting to restart the
     *                                      process
     * @param string   $table               - The staging table name (i.e., 'staging_bottling_plan')
     * @param array    $comparisons         - Array of comparison check function names (i.e., ["missingWPI", "nullWPI",
     *                                      "missingBPI", "missingBFI", etc.])
     * @param string   $dataSet             - The class name of the FileImportComparison that is being used (i.e.
     *                                      "BottlingPlan"
     *                                      for app\FileImportComparisons\BottlingPlan.php)
     * @param string   $readable            - The human-readable name of the file import (i.e. "Bottling Plan")
     * @param          $failedNotification  - The name of the Notification (Notification->notification) that should be
     *                                      sent as part of the processValidationFailures function.
     * @param          $successNotification - The name of the Notification (Notification->notification) that should be
     *                                      sent when the import process completes successfully.
     * @param callable $loadFunc            - The function that should be used to load the data (i.e.,
     *                                      importDataUpdate()
     *                                      - set this up in the individual controller)
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\Http\RedirectResponse|\Illuminate\Http\Response|\Illuminate\Routing\Redirector|\Illuminate\View\View
     */
    public function processImport(
        $noAsync,
        $waitTime,
        $table,
        $comparisons,
        $dataSet,
        $readable,
        $failedNotification,
        $successNotification,
        $controller
    ) {

        // Make sure that we have a file to work with
        if (!Request::file()) {
            $message = 'No file has been provided for the import.';

            (new ValidationFailureProcessor)->processMissingFile('fileImportMissingFile', $dataSet);

            return $this->getStandardApiResponse(Request::all(), $message, 'input', 400);
        }

        $fis = new FileImportService;

        $logic = new FileImportLogic($fis);

        // Get the necessary file details
        list($filename, $movedFile) = $fis->moveFile(Request::file('file'));

        // Set the JobLog job_type to keep it consistent across the board
        // @NOTE: If this changes, the noAsyncArgs[] logic below must be updated to match it
        $jobType = 'Upload - ' . $table;

        $noAsyncArgs = [];
        // Set the correct JobLog job_type based on the noAsync values
        // @NOTE: Make sure this always matches up to the jobType variable set above
        if (is_countable($noAsync) && count($noAsync) > 0) {
            foreach ($noAsync as $stagingTable) {
                $noAsyncArgs[] = 'Upload - ' . $stagingTable;
            }
        }

        // Determine whether or not this process was started via the UI...
        $fromVino = $this->isFromVino();

        if ($fromVino) {
            // We need to return responses and/or views in real time
            // Check whether or not it is safe to process this file right now
            $runningJobs = JobLog::isRunning($noAsyncArgs)->get();

            if ($runningJobs && is_countable($runningJobs) && count($runningJobs) > 0) {
                // We can not process this file right now - let the user know they need to wait
                $redirectSlug  = (new ValidationFailureProcessor)->getUrlFromNotificationName($failedNotification);
                $runningJobIds = '';

                foreach ($runningJobs as $runningJob) {
                    $runningJobIds .= '&job_ids[]=' . $runningJob->id;
                }

                return redirect($redirectSlug . '?wait_time=' . $waitTime . $runningJobIds);
            } else {
                $isQueued = false;

                $logicResponse = $logic->runFileImport(
                    $isQueued,
                    $fromVino,
                    $jobType,
                    $filename,
                    $movedFile,
                    $table,
                    $comparisons,
                    $dataSet,
                    $readable,
                    $failedNotification,
                    $successNotification,
                    $controller
                );

                if ($logicResponse['is_view']) {
                    return view($logicResponse['view_file']);
                }

                return $this->getStandardApiResponse($logicResponse['content'], $logicResponse['message'], 'content',
                    $logicResponse['status']);
            }

        } else {
            // This can be safely queued
            // Determine which Queue to place it on
            $queue = $this->getQueue($dataSet);

            $job = (new ProcessFileImport(
                $logic,
                $noAsyncArgs,
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
            ))->onConnection($queue)->onQueue($queue);

            $this->dispatch($job);

            $responseMessage =
                'The '
                . $readable
                . ' file import has been queued for processing. Further details will be sent via email.';

            return $this->getStandardApiResponse($filename, $responseMessage, 'queued_file');
        }
    }

    /**
     * Determine whether the request came from inside the vino app, or from the file transfer service.
     * Returns true if it came from the vino app.
     *
     * @return bool
     */
    function isFromVino()
    {

        $referrer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'nope://not-from-vino-app';
        $host     = $_SERVER['HTTP_HOST'];

        $referrerParts = explode('://', $referrer);
        $hostLength    = strlen($host);

        $referrerMatch = substr($referrerParts[1], 0, $hostLength);

        return $referrerMatch === $host;
    }

    /**
     * Determine which queue/connection this import should be placed on, and return the name.
     *
     * @param string $dataSet
     *
     * @return string
     */
    function getQueue($dataSet)
    {

        switch ($dataSet) {
            case 'BarrelData':
            case 'BarrelGroup':
            case 'InventoryData':
            case 'MasterTank':
                $queue = 'vessel_import';
                break;
            case 'BlockStyles':
            case 'HarvestAllocationAgCode':
            case 'HarvestBlockAgCode':
            case 'HarvestQualityAgCode':
            case 'HarvestSchedulerBlock':
            case 'HarvestSchedulerData':
            case 'HarvestWeighTagAgCode':
                $queue = 'harvest_import';
                break;
            case 'LabQaHistory':
            case 'OperationsData':
                $queue = 'lab_import';
                break;
            default:
                $queue = 'file_import';
        }

        return $queue;
    }
}
