<?php

return [

    /*
     * The bound SmsGateway/PushGateway implementation for each channel.
     * Only 'log' is implemented in this phase (see AppServiceProvider) —
     * writes to the application log instead of placing a real network
     * call, exactly like MAIL_MAILER=log does for email. Point these at
     * a real provider once an SMS/push account exists and its gateway
     * class is bound.
     */

    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),
    ],

    'push' => [
        'driver' => env('PUSH_DRIVER', 'log'),
    ],

];
