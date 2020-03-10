<?php


namespace ZamApps\ZamInterface\Services\AWS\SMS;

use ZamApps\ZamInterface\FileImport\SendSMS;
use Aws\Laravel\AwsFacade;
use Illuminate\Foundation\Bus\DispatchesJobs;


class AwsSmsService
{
    use DispatchesJobs;
    private $sendOnlyToDevs;

    public function __construct()
    {
        $this->sendOnlyToDevs = config('notification.send_only_to_developers');
    }

    public function sendSMS($phone_number, $message, $dataType = 'String', $stringValue = 'Transactional', $queue = false)
    {
        if ($queue) {
            $this->dispatch(new SendSMS($phone_number, $message, $dataType, $stringValue));
        } else {
            $sms = AwsFacade::createClient('sns');
            $sms->publish([
                'Message' => $message,
                'PhoneNumber' => $phone_number,
                'MessageAttributes' => [
                    'AWS.SNS.SMS.SMSType' => [
                        'DataType' => $dataType,
                        'StringValue' => $stringValue,
                    ]
                ],
            ]);
        }
    }

    public function sendBulkSMS($recipients, $message, $queue = false, $dataType = 'String', $stringValue = 'Transactional')
    {
        $phone_number = null;
        foreach ($recipients as $recipient) {
            if (!$this->sendOnlyToDevs) {
                $phone_number = $recipient->mobile_phone_country_code . $recipient->mobile_phone;
            } else {
                $phone_number = $recipient;
            }
            $this->sendSMS($phone_number, $message, $dataType, $stringValue, $queue);
        }
    }
}
