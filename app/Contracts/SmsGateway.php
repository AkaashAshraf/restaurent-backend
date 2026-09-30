<?php

namespace App\Contracts;

/**
 * The seam a real SMS provider (Twilio, Vonage, ...) plugs into. No
 * provider account exists yet, so the only bound implementation is
 * LogSmsGateway (see AppServiceProvider) — the same "model the shape
 * now, swap the driver later" approach PaymentService's gateway-shaped
 * API took in Phase 7 for payments.
 */
interface SmsGateway
{
    public function send(string $to, string $message): void;
}
