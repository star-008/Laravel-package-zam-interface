<?php

namespace ZamApps\ZamInterface\FileImport;

use ZamApps\ZamInterface\Services\FileImportService;
use vinoEZ\laravel\Facades\JobLogger;
use Illuminate\Support\Facades\Log;

class FileImportLogic
{

    protected $fis;

    /**
     * FileImportLogic constructor.
     *
     * @param FileImportService $fis
     */
    public function __construct(FileImportService $fis)
    {

        $this->fis = $fis;
    }

    /**
     * @param bool     $isQueued
     * @param bool     $isFromVino
     * @param string   $jobType
     * @param string   $filename
     * @param resource $movedFile
     * @param string   $table
     * @param array    $comparisons
     * @param string   $dataSet
     * @param string   $readable
     * @param string   $failedNotification
     * @param string   $successNotification
     *
     * @return array
     */
    public function runFileImport(
        $isQueued,
        $isFromVino,
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
    ) {

        Log::info('FileImport Logic has been started...' . $jobType . ' Uploading ' . $filename . ' into ' . $table);

        $jobID = JobLogger::initiateJob($jobType, 'Uploading ' . $filename . ' into ' . $table);

        // @description - this actually puts the data from the file into the staging table to be verified and processed
        $ret = $this->fis->processFile($jobID, $movedFile, $table, true);

        // Check to make sure that the file was added to staging correctly...
        if ($ret['statusCode'] !== 200) {
            $failedStagingMsg =
                $ret['statusCode'] == 500 ? $ret['content']['error']['message'] : $ret['content']['message'];

            Log::info('Staging Failed! ' . $failedStagingMsg . ":" . json_encode($ret['content']));
            JobLogger::killJob($jobID, $failedStagingMsg, json_encode($ret['content']));

            $responseErrors = $ret['statusCode'] == 500 ? [] : $ret['content']['errorRows'];
            // Send a Notification to interested parties...
            (new ValidationFailureProcessor)->processStagingFailure('fileImportProcessError', $filename, $movedFile,
                $failedStagingMsg, $responseErrors);

            if (!$isQueued) {
                $response =
                    [
                        'is_view' => false,
                        'message' => $failedStagingMsg,
                        'content' => $ret['content'],
                        'status'  => $ret['statusCode'],
                    ];

                return $response;
            }
        }

        $fails = [];

        //*** calls each comparison
        Log::info('Running Import Comparisons for ' . $filename);
        JobLogger::addJobDetail($jobID, 'File Import Comparisons', 'Running Import Comparisons for ' . $filename);
        foreach ($comparisons as $comparison) {
            // @NOTE! returns Response object
            $bad_records = $this->fis->importDataComparisons(['comparison' => $comparison, 'dataSet' => $dataSet]);
            $content     = json_decode($bad_records->getContent());
            if ($content->rowCount > 0) {
                $fails[$comparison] = $content;
            }
        }

        // if !empty($fails) we need to return $fails/ email it somewhere to let people know cleanup is required
        if (!empty($fails)) {
            $failedValidationMsg = $filename . ' has failed validation';
            (new ValidationFailureProcessor)->processValidationFailures($fails, $failedNotification, $filename,
                $movedFile);

            Log::info($failedValidationMsg . ": " . json_encode($fails));
            JobLogger::killJob($jobID, $failedValidationMsg, json_encode($fails));

            $apiResponse =
                ['is_view' => false, 'message' => $failedValidationMsg, 'content' => $fails, 'status' => 400];

            if (!$isQueued) {
                if ($isFromVino) {
                    return ['is_view' => true, 'view_file' => 'FileImport/reprocessImportFileComparisonFail'];
                }

                return $apiResponse;
            } else{
                // We have to stop the Queue from continuing on at this point...
                Log::info('Validation has failed. Aborting Transform/Load process.');

                return $apiResponse;
            }
        }

        //*** all good; load the actual data:
        Log::info('Loading data from ' . $table);
        JobLogger::addJobDetail($jobID, 'File Import Load', 'Loading data from ' . $table);
        $statuses = \App::call($controller . '@importDataUpdate');

        foreach ($statuses as $status) {
            if ($status['success']) {
                // Send a Notification to interested parties...
                (new ValidationFailureProcessor)->processValidationSuccess($successNotification, $filename);
                $successMessage = $readable . ' File Transfer successful';

                Log::info($successMessage);
                JobLogger::stopJob($jobID, $successMessage);

                if (!$isQueued) {
                    if ($isFromVino) {
                        return ['is_view' => true, 'view_file' => 'FileImport/reprocessImportFileSuccess'];
                    }

                    $response =
                        ['is_view' => false, 'message' => $successMessage, 'content' => $status, 'status' => 200];

                    return $response;
                }
            } else {

                $failureMessage = $readable . ' File Transfer failed';

                // Determine whether the file was empty...
                if ($status['empty']) {
                    $failureMessage .= ' because the file did not contain any data';

                    // Send a Notification to interested parties...
                    (new ValidationFailureProcessor)->processLoadFailure('fileImportNoData', $filename, $movedFile);
                } else {
                    // Send a Notification to interested parties...
                    (new ValidationFailureProcessor)->processLoadFailure('fileImportUnprocessed', $filename,
                        $movedFile);
                }

                Log::info($failureMessage . ": " . json_encode($status));
                JobLogger::killJob($jobID, $failureMessage, json_encode($status));

                if (!$isQueued) {
                    if ($isFromVino) {
                        return ['is_view' => true, 'view_file' => 'FileImport/reprocessImportFileLoadFail'];
                    }

                    $response =
                        ['is_view' => false, 'message' => $failureMessage, 'content' => $status, 'status' => 400];

                    return $response;
                }
            }
        }

    }
}
