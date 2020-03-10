<?php

namespace ZamApps\ZamInterface\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesResources;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Event;
use Exception;

class Controller extends BaseController
{

    use AuthorizesRequests, AuthorizesResources, DispatchesJobs, ValidatesRequests;

    public function __constructor()
    {
    }

    /**
     * Setup the layout used by the controller.
     *
     * @return void
     */
    protected function setupLayout()
    {

        if (!is_null($this->layout)) {
            $this->layout = view($this->layout);
        }
    }

    /**
     * Return a Status Code for an Exception Response based on the Exception's Status Code with a backup code in case
     * we can not retrieve a proper Status Code from the Exception.
     *
     * @param Exception $e      - the Exception object
     * @param int       $backup - Status Code backup to use if code can not be retrieved from error exception
     *
     * @return int
     */
    public function setStatus(Exception $e, $backup)
    {

        $status = $e instanceof \HttpException ? $e->getStatusCode() : $e->getCode();

        return !$status || $status > 530 || $status < 100 ? $backup : $status;
    }

    /**
     * Return a properly formatted Response for an error Exception based on the details of the Exception and any
     * additional information that is passed through.
     *
     * @param Exception   $e          - The Exception object
     * @param int         $backup     - Status Code backup to use if code can not be retrieved from error exception
     * @param null|string $message    - Optional message to include in lieu of error message
     * @param array       $additional - optional additional key => value pairs that should be added to the response
     *
     * @return Response
     */
    public function getErrorResponseForException(Exception $e, $backup, $message = null, $additional = [])
    {

        $status = $this->setStatus($e, $backup);
        $errMsg = $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine();
        // If this is a SQL error, let's present it in a readable fashion
        if (strpos($errMsg, 'SQL:')) {
            $message = $message . ': A database error has occurred';
        }

        $content = ['message' => $message, 'errorMessage' => $errMsg];

        if (count($additional) > 0) {
            foreach ($additional as $key => $value) {
                $content[$key] = $value;
            }
        }

        return response($content, $status);
    }

    /**
     * Return a consistent, properly formatted successful Json Response containing elements listed below.
     *
     * @param array|string|null data - The data to return as the 'content' (or $dataName) value in the response.data
     *                               object
     * @param string $message        - The success message to return as the 'message' value in the response
     * @param string $dataName       - An optional identifier for the data array, if it should be called something
     *                               other than 'content', which is the default
     * @param int    $status         - An optional status code, if it should something other than 200, which is the
     *                               default
     * @param array  $additional     - optional additional key => value pairs that should be added to the response
     *
     * @return Response - A success response containing a data array, success message and status code
     */
    public function getStandardApiResponse(
        $data,
        $message,
        $dataName = 'content',
        $status = 200,
        $additional = [],
        $count = null
    ) {

        if (is_null($count)) {
            if (is_countable($data)) {
                $count = count($data);
            }
        }

        // Build everything in the proper order: 1. Message 2. Count (if applicable), 3. Data 4. Addt'l (if applicable)
        $responseArray = ['message' => $message];

        if (!is_null($count)) {
            $responseArray['count'] = $count;
        }

        $responseArray[$dataName] = $data;

        if (is_countable($additional) && count($additional) > 0) {
            foreach ($additional as $key => $value) {
                $responseArray[$key] = $value;
            }
        }

        return response($responseArray, $status);
    }

    /**
     * Returns boolean (true/false) as to whether or not the request is from the mobile app.
     *
     * @return bool
     */
    public function wantsMobile()
    {

        return Request::header('vino_app') == 'mobile';
    }
}
