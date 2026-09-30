<?php

namespace App\Services\Sms;

use App\Contracts\SmsGateway;
use Illuminate\Support\Facades\Log;

/**
 * Default SMS driver: writes to the application log instead of placing
 * a real network call. Mirrors MAIL_MAILER=log's role for email — safe
 * to run in any environment (including this test suite) without an SMS
 * account, and replaceable by binding a different SmsGateway
 * implementation in AppServiceProvider once a real provider (Twilio,
 * Vonage, ...) is configured via notifications.sms.driver.
 */
class LogSmsGateway implements SmsGateway
{
    public function send(string $to, string $message): void
    {
        Log::info("[sms] to={$to} message=\"{$message}\"");
    }
}
