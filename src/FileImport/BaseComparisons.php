<?php

namespace ZamApps\ZamInterface\FileImport;

use Exception;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BaseComparisons
{

    const PLACEHOLDER_STRING = "UNKNOWN";
    const PLACEHOLDER_LOCATION = "UNKNOWN";
    const PLACEHOLDER_DECIMAL = 0.00;
    const PLACEHOLDER_INT = 0;

    /**
     * Returns array of records that have null values for the specified required field.
     *
     * @param string      $requiredField - The column name of the attribute that can not be null
     * @param string      $source        - The name of the database table that contains the columns that can not be null
     * @param bool|string $rawSelect     - A SQL string to be passed through as the 'SELECT' statement, if applicable
     *
     * @return array
     */
    public function missingFieldSql($requiredField, $source, $rawSelect = false)
    {

        $query = DB::table($source)->whereNull($requiredField);

        if ($rawSelect) {
            $query->select(DB::raw($rawSelect));
        }

        return $query->get();
    }

    /**
     * Returns array of records that contain data that should, but does not, match to an existing model using data in
     * the staging table.
     *
     * @param string      $source    - The name of the database table that contains the data which should match an
     *                               existing model
     * @param string      $dbKey     - The attribute name of the db column that contains the data which should match an
     *                               existing model
     * @param             $model     - The model that we are checking for (send through as Model::class)
     * @param string      $modelKey  - The attribute name that we are checking against for the model that should exist
     * @param bool|string $rawSelect - A raw SQL string to be passed through as the 'SELECT' statement, if applicable
     *
     * @return array
     */
    public function existingModelSql($source, $dbKey, $model, $modelKey, $rawSelect = false)
    {

        $dbRows   = DB::table($source)->get();
        $dbRowIDs = [];

        foreach ($dbRows as $dbRow) {
            try {
                $model::where($modelKey, $dbRow->$dbKey)->firstOrFail();
            } catch (\Exception $e) {
                $dbRowIDs[] = $dbRow->id;
            }
        }

        if (count($dbRowIDs) > 0) {
            $query = $this->getQueryForIdArray($source, $dbRowIDs, $rawSelect);
        }

        $returnVal = count($dbRowIDs) > 0 ? $query->get() : [];

        return $returnVal;
    }

    /**
     * Returns array of records that contain data that should, but does not, match to an existing model using data in
     * the staging table. If this data is nullable, and a null value is passed through, the record will pass without
     * issue.
     *
     * @param string      $source    - The name of the database table that contains the data which should match an
     *                               existing model
     * @param string      $dbKey     - The attribute name of the db column that contains the data which should match an
     *                               existing model
     * @param             $model     - The model that we are checking for (send through as Model::class)
     * @param string      $modelKey  - The attribute name that we are checking against for the model that should exist
     * @param bool|string $rawSelect - A raw SQL string to be passed through as the 'SELECT' statement, if applicable
     *
     * @return array
     */
    public function nullableExistingModelSql($source, $dbKey, $model, $modelKey, $rawSelect = false)
    {

        $dbRows   = DB::table($source)->get();
        $dbRowIDs = [];

        foreach ($dbRows as $dbRow) {
            if (!is_null($dbRow->$dbKey)) {
                try {
                    $model::where($modelKey, $dbRow->$dbKey)->firstOrFail();
                } catch (\Exception $e) {
                    $dbRowIDs[] = $dbRow->id;
                }
            }
        }

        if (count($dbRowIDs) > 0) {
            $query = $this->getQueryForIdArray($source, $dbRowIDs, $rawSelect);
        }

        $returnVal = count($dbRowIDs) > 0 ? $query->get() : [];

        return $returnVal;
    }

    /**
     * Returns array of records that contain dates that can not be parsed by Carbon.
     *
     * @param string      $source    - The name of the database table that contains the date which should be able to
     *                               be parsed by Carbon
     * @param string      $dbKey     - The attribute name of the db column that contains the date which should be able
     *                               to be parsed by Carbon
     * @param string      $format    - The format that the parsed date should be returned in (i.e., 'Y-m-d', which is
     *                               the default)
     * @param bool|string $rawSelect - A SQL string to be passed through as the 'SELECT' statement, if applicable
     *
     * @return array
     */
    public function validDateSql($source, $dbKey, $format = 'Y-m-d', $rawSelect = false)
    {

        $dbRows   = DB::table($source)->get();
        $dbRowIDs = [];

        foreach ($dbRows as $dbRow) {
            $parsedDate = $this->validDate($dbRow->$dbKey, $format);
            if (!$parsedDate) {
                $dbRowIDs[] = $dbRow->id;
            } else {
                $dbRow->$dbKey = $parsedDate;
            }
        }

        if (count($dbRowIDs) > 0) {
            $query = $this->getQueryForIdArray($source, $dbRowIDs, $rawSelect);
        }

        return count($dbRowIDs) > 0 ? $query->get() : [];
    }

    /**
     * Returns array of records that contain dates that can not be parsed by Carbon, but allows for null values.
     *
     * @param string      $source    - The name of the database table that contains the date which should be able to
     *                               be parsed by Carbon
     * @param string      $dbKey     - The attribute name of the db column that contains the date which should be able
     *                               to be parsed by Carbon
     * @param string      $format    - The format that the parsed date should be returned in (i.e., 'Y-m-d', which is
     *                               the default)
     * @param bool|string $rawSelect - A SQL string to be passed through as the 'SELECT' statement, if applicable
     *
     * @return array
     */
    public function validNullableDateSql($source, $dbKey, $format = 'Y-m-d', $rawSelect = false)
    {

        $dbRows   = DB::table($source)->get();
        $dbRowIDs = [];

        foreach ($dbRows as $dbRow) {
            if (!is_null($dbRow->$dbKey)) {
                $parsedDate = $this->validDate($dbRow->$dbKey, $format);
                if (!$parsedDate) {
                    $dbRowIDs[] = $dbRow->id;
                } else {
                    $dbRow->$dbKey = $parsedDate;
                }
            }
        }

        if (count($dbRowIDs) > 0) {
            $query = $this->getQueryForIdArray($source, $dbRowIDs, $rawSelect);
        }

        return count($dbRowIDs) > 0 ? $query->get() : [];
    }

    /**
     * @param string      $source    - The name of the database table that contains the data that should be able to be
     *                               set as a float
     * @param string      $dbKey     - The attribute name of the db column that contains the data that should be able
     *                               to be set as a float
     * @param bool|string $rawSelect - A SQL string to be passed through as the 'SELECT' statement, if applicable
     *
     * @return array
     */
    public function validFloatSql($source, $dbKey, $rawSelect = false)
    {

        $dbRows   = DB::table($source)->get();
        $dbRowIDs = [];

        foreach ($dbRows as $dbRow) {
            $float = $this->setFloatVal($dbRow->$dbKey);
            if (!$float) {
                $dbRows[] = $dbRow->id;
            } else {
                $dbRow->$dbKey = $float;
            }
        }

        if (count($dbRowIDs) > 0) {
            $query = $this->getQueryForIdArray($source, $dbRowIDs, $rawSelect);
        }

        return count($dbRowIDs) > 0 ? $query->get() : [];
    }

    /**
     * Checks an array of required fields, returning false if all required fields have been provided, or a specific
     * message string denoting any/all missing fields.
     *
     * @param array $requiredFields
     *
     * @return bool|string
     */
    public function missingFieldsMsg($requiredFields)
    {

        $msg = '';

        foreach ($requiredFields as $key => $value) {
            if (is_null($value)) {
                $msg .= $key . ' is required, but was not provided, ';
            }
        }

        return $msg === '' ? false : trim(rtrim($msg, ','));
    }

    /**
     * Attempts to retrieve an existing model from the key => value pair provided, returning the model if it exists, or
     * an error message if it is missing.
     *
     * @param string $model - The model that we are checking for (send through as Model::class)
     * @param string $key   - The attribute name that we are checking against
     * @param        $val   - The value of the attribute that we are checking against
     *
     * @return string|\Illuminate\Database\Eloquent\Model
     */
    public function existingModelsMsg($model, $key, $val)
    {

        $msg = '';

        try {
            $existing = $model::where($key, $val)->firstOrFail();
        } catch (Exception $e) {
            $msg .= $model . ' with ' . $key . ' of "' . $val . '" does not exist';
        }

        return $msg === '' ? $existing : $msg;
    }

    /**
     * Attempt to parse a provided date string with Carbon and return it in the specified format, or return false if it
     * can not be parsed.
     *
     * @param string $dateForParsing - The date that should be parsed
     * @param string $format         - The format that the parsed date should be returned in (i.e., 'Y-m-d', which is
     *                               the default)
     *
     * @return bool|string
     */
    public function validDate($dateForParsing, $format = 'Y-m-d')
    {

        $dateIsValid = is_null($dateForParsing) ? false : true;

        try {
            $carbonDate = Carbon::parse($dateForParsing)->format($format);
        } catch (Exception $e) {
            $dateIsValid = false;
        }

        return $dateIsValid ? $carbonDate : $dateIsValid;
    }

    /**
     * Attempt to cast a value as a float and return it, or return false if it can not be cast to a float.
     *
     * @param mixed $value
     *
     * @return bool|float
     */
    public function setFloatVal($value)
    {

        $isFloat = is_null($value) ? false : true;

        if (settype($value, 'float')) {
            $floatVal = floatval($value);
        } else {
            $isFloat = false;
        }

        return $isFloat ? $floatVal : $isFloat;
    }

    /**
     * Returns a WPI that includes the base EUR + Harvest Period 2 digit year for use in transformations, or returns
     * false if WPI or Year are missing.
     *
     * @param string $wpi  - The Base EUR provided by the client
     * @param string $year - The Harvest Period provided by the client
     *
     * @return bool|string
     */
    public function buildWpi($wpi, $year)
    {

        return !is_null($wpi) && !is_null($year) ? $wpi . substr($year, -2) : false;
    }

    /**
     * @param string $source    - The name of the DB table we will be querying
     * @param array  $idArray   - The array of IDs that we will be pulling
     * @param bool   $rawSelect - A SQL string to be passed through as the 'SELECT' statement, if applicable
     *
     * @return mixed
     */
    public function getQueryForIdArray($source, $idArray, $rawSelect = false)
    {

        $query = DB::table($source)->whereIn('id', $idArray);

        if ($rawSelect) {
            $query->select(DB::raw($rawSelect));
        }

        return $query;
    }

    /**
     * Accepts arrays of errors, new and (when applicable) updated data that have gone through the transformation or
     * load process, and returns an array containing the necessary data to return a response with.
     *
     * @param string     $type      - The type of function the data has been processed with - can be 'transform' or
     *                              'load'
     * @param string     $modelType - The name of the model that has been processed (i.e., "MasterTank")
     * @param array      $errors    - The array of error arrays that have been built within the process
     * @param array      $new       - The array of successful transforms or loads that have been built within the
     *                              process
     * @param array|null $updated   - Optional/only applicable to functions that handle updates as well as creates ~
     *                              The array of successful updates that have been built within the process
     * @param array|null $deleted   - Optional/only applicable to functions that handle deletes as well as
     *                              updates/creates ~ The array of successfully records that have been successfully
     *                              deleted within the process
     *
     * @return array
     */
    public function getReturnArray($type, $modelType, $errors, $new, $updated = null, $deleted = null)
    {

        $msgPrefix   = $modelType;
        $msgSuffix   = str_plural($modelType);
        $createdWord = 'created';

        $successKey = 'new_data';
        $updatedKey = 'updated_data';
        $deletedKey = 'deleted_data';

        $newCount   = is_countable($new) ? count($new) : 0;
        $errorCount = is_countable($errors) ? count($errors) : 0;

        if ($type === 'transform') {
            $msgPrefix   .= ' transformations ';
            $createdWord = 'transformed';
            $successKey  = 'transformed_data';
        } else {
            $msgPrefix .= ' loads ';
            $msgSuffix = 'new ' . $msgSuffix;
        }

        $successArray = [$successKey => ['count' => $newCount, 'records' => $new]];
        $failedArray  = ['errors' => ['count' => $errorCount, 'records' => $errors]];

        $msgPrefix .= 'completed with ';
        $msgSuffix .= ' were successfully ' . $createdWord;
        $msg       = $msgPrefix . $errorCount . ' errors. ' . $newCount . ' ' . $msgSuffix;

        if ((is_null($updated) || (is_countable($updated)) && count($updated) < 1)
            && (is_null($deleted)
                || is_countable($deleted)
                   && count($deleted) < 1)
        ) {
            $msg .= '.';
        } else {
            if (!is_null($updated) || (is_countable($updated) && count($updated) > 0)) {
                $updatedCount              = count($updated);
                $msg                       .= ' and '
                                              . $updatedCount
                                              . ' existing '
                                              . str_plural($modelType)
                                              . ' were successfully updated';
                $successArray[$updatedKey] = ['count' => $updatedCount, 'records' => $updated];
            }

            if (!is_null($deleted) || (is_countable($deleted) && count($deleted) > 0)) {
                $deletedCount              = count($deleted);
                $msg                       .= ' and '
                                              . $deletedCount
                                              . ' existing '
                                              . str_plural($modelType)
                                              . ' were successfully deleted';
                $successArray[$deletedKey] = ['count' => $deletedCount, 'records' => $deleted];
            }

            $msg .= '.';
        }

        // Determine whether the file was empty by checking for data in all arrays
        $wasEmpty =
            (is_null($deleted) || (is_countable($deleted) && count($deleted) < 1))
            && (is_null($updated) || (is_countable($updated) && count($updated) < 1))
            && $newCount < 1
            && $errorCount < 1;

        if ($wasEmpty) {
            $status = false;
        } else {
            $status =
                $successArray[$successKey]['count'] > 0
                || (!is_null($updated) && is_countable($updated)
                    && array_key_exists($updatedKey, $successArray)
                    && $successArray[$updatedKey]['count'] > 0)
                || (!is_null($deleted) && is_countable($deleted)
                    && array_key_exists($deletedKey, $successArray)
                    && $successArray[$deletedKey]['count'] > 0)
                    ? true : false;
        }

        return [
            'message'    => $msg,
            'success'    => $status,
            'successful' => $successArray,
            'failed'     => $failedArray,
            'empty'      => $wasEmpty,
        ];
    }
}
