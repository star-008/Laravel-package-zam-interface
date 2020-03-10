<?php

namespace ZamApps\ZamInterface\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class FileImportService
{

    /*
     * @description - each rule applied to the data imports will hit this function, which in turn runs a validation-style check
     * @param array $input
     * */
    public function importDataComparisons($input)
    {

        if (array_has($input, 'comparison') && array_has($input, 'dataSet')) {
            return $this->masterDataComparisons(array_get($input, 'dataSet', 'default'),
                array_get($input, 'comparison', 'default'));
        }

        $content = [
            'message' => "Input must include the comparison and data set",
            'class'   => 'flash-bad',
        ];

        return \response($content, 400);
    }

    public function masterDataComparisons($dataSet, $comparison)
    {

        $rowCount         = null;
        $message          = null;
        $statusCode       = 200;
        $data             = null;
        $header           = null;
        $notificationFlag = null;

        $instance = 'App\\FileImportComparisons\\' . $dataSet;

        if (!class_exists($instance)) {
            $content = [
                'dataSet'          => $dataSet,
                'comparison'       => $comparison,
                'rowCount'         => 1,
                'message'          => $dataSet . ' does not exist in FileImportComparisons',
                'data'             => $data,
                'header'           => $header,
                'notificationFlag' => $notificationFlag,
            ];

            return \response($content, 400);
        }

        if (!method_exists($instance, $comparison)) {
            $content = [
                'dataSet'          => $dataSet,
                'comparison'       => $comparison,
                'rowCount'         => 1,
                'message'          => $comparison . ' is not a valid comparison in ' . $dataSet,
                'data'             => $data,
                'header'           => $header,
                'notificationFlag' => $notificationFlag,
            ];

            return \response($content, 400);
        }

        $fic  = new $instance; // this is a quirk of php - cannot be written in a single line
        $data = $fic->{$comparison}();
        if (count($data) > 0) {
            $header = array_keys(get_object_vars($data[0]));
        }
        $rowCount = count($data);

        $content = [
            'dataSet'          => $dataSet,
            'comparison'       => $comparison,
            'rowCount'         => $rowCount,
            'message'          => $message,
            'data'             => $data,
            'header'           => $header,
            'notificationFlag' => $notificationFlag,
        ];

        return \response($content, $statusCode);
    }

    /**
     * @return array
     */
    public function moveFile($file)
    {

        $uploadDir = "uploads/";
        $filename  = $file->getClientOriginalName();
        $movedFile = $file->move(storage_path() . "/" . $uploadDir, $filename);

        return [$filename, $movedFile];
    }

    /*
     * @param int $jobId
     * @param file $movedFile - file taken from local temp storage
     * @param string $table - name of the table we will be later inserting to, for validation only (could be separated out)
     * @param bool $autoProcess - True/false flag as to whether this is being run from the FileImportLogic, or if it's
     * coming from the UI. False = UI and is the default.
     *
     * @return array
     * */
    function processFile($jobID, $movedFile, $table, $autoProcess = false)
    {
        ini_set('max_execution_time', 1800); // 30 minutes
        ini_set('memory_limit', '2048M');

        // Assume the best
        $statusCode = 200;

        // Read file into array
        try {
            $rows = \Excel::selectSheetsByIndex(0)->load($movedFile, function ($reader) {

                // Fix column headers with carriage return
                $row        = 1;
                $worksheet  = $reader->getExcel()->getSheet();
                $lastColumn = $worksheet->getHighestColumn();
                $lastColumn++;
                for ($column = 'A'; $column != $lastColumn; $column++) {
                    $cell = $worksheet->getCell($column . $row)->getValue();
                    if (strstr($cell, PHP_EOL)) {
                        $worksheet->getCell($column . $row)->setValue(str_replace(PHP_EOL, '_', $cell));
                    }
                }
            })->toArray();
        } catch (\Exception  $e) {
            $statusCode = 500;
            $content    = [
                'error' => [
                    'message' => 'There was an error with the import. '
                                 . $e->getMessage()
                                 . ' Please notify support@vinoez.com of this error message and include your import file ',
                ],
            ];

            return ['content' => $content, 'statusCode' => $statusCode];
        }

        $fieldsToBeUnset = [];

        if (isset($rows[0])) {
            $fieldsToBeUnset = $this->findMissingFields($jobID, $table, $rows[0]);
        }

        // Truncate table
        \DB::table($table)->truncate();
        \JobLogger::addJobDetail($jobID, "Truncated " . $table, '');

        // Insert data into table
        $content['insertCount']  = 0;
        $content['errorCount']   = 0;
        $content['errorRows']    = [];
        $content['skippedCount'] = 0;

        // Assume the best until otherwise
        $statusCode = 200;

        foreach ($rows as $row) {
            if(is_countable($fieldsToBeUnset) && count($fieldsToBeUnset) > 0){
                // catch the data types don't match
                foreach ($fieldsToBeUnset as $field) {
                    unset($row[$field]);
                }
            }

            try {
                \DB::table($table)->insert($row);
                $content['insertCount']++;
            } catch (\Exception $e) {
                // we are not aborting if this is vessel lot data
                if ($table == 'staging_vessel_lot_codes') {
                    $content['skippedCount']++;
                } else {
                    $content['errorCount']++;
                    $row['errorMessage']    = $e->getMessage();
                    $content['errorRows'][] = $row;
                    $content['message']     = "Not all rows were successfully imported.";
                    $statusCode             = 400;
                }
            }
        }

        $content['recordCount'] = count($rows);

        \JobLogger::addJobDetail($jobID, "Record count", $content['recordCount']);
        \JobLogger::addJobDetail($jobID, "Skipped count", $content['skippedCount']);
        \JobLogger::addJobDetail($jobID, "Insert count", $content['insertCount']);

        if ($statusCode == 200) {
            $content['message'] = "File successfully imported into " . $table;
            $content['class']   = 'flash-good';
            if(!$autoProcess){
                \JobLogger::stopJob($jobID, "Import successful");
            }
        } else {
            $content['class'] = 'flash-bad';
        }

        return ['content' => $content, 'statusCode' => $statusCode];
    }

    function findMissingFields($jobID, $table, $headerRow)
    {

        // Get import file fields
        $fields = array_keys($headerRow);

        \JobLogger::addJobDetail($jobID, "Import fields ", json_encode($fields));

        $fieldsToBeUnset = [];

        // Look up $table fields
        $schema       = \DB::getDoctrineSchemaManager();
        $tableColumns = [];
        foreach ($schema->listTableColumns($table) as $key => $value) {
            if ($key != 'id') {
                array_push($tableColumns, $key);
            }
        }

        // Compare table and file fields
        foreach ($fields as $field) {
            if (!in_array($field, $tableColumns) || $field === 0) {
                $fieldsToBeUnset[] = $field;
            }
        }

        return $fieldsToBeUnset;
    }
}
