<?php

namespace ZamApps\ZamInterface\Jobs;

use Aws\Laravel\AwsFacade;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendSMS extends Job implements ShouldQueue
{
    use InteractsWithQueue, SerializesModels;
    private $sms;
    private $phone_number;
    private $message;
    private $dataType;
    private $stringValue;


    public function __construct($phone_number, $message, $dataType = 'String', $stringValue = 'Transactional')
    {
        $this->phone_number = $phone_number;
        $this->message = $message;
        $this->dataType = $dataType;
        $this->stringValue = $stringValue;

    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $this->sms = AwsFacade::createClient('sns');
        $this->sendSMS();
    }

    public function sendSMS()
    {
        $this->sms->publish([
            'Message' => $this->message,
            'PhoneNumber' => $this->phone_number,
            'MessageAttributes' => [
                'AWS.SNS.SMS.SMSType' => [
                    'DataType' => $this->dataType,
                    'StringValue' => $this->stringValue,
                ]
            ],
        ]);

    }
}
