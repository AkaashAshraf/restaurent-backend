<?php

namespace App\Contracts;

/**
 * The seam a real push provider (FCM, APNs, ...) plugs into. Same
 * "log driver until a real account exists" story as SmsGateway.
 */
interface PushGateway
{
    public function send(string $deviceToken, string $title, string $body, array $data = []): void;
}
