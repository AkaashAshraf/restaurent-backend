<?php

namespace App\Notifications\Channels;

use App\Contracts\PushGateway;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Notifications\Notification;

/**
 * Fans out to every device token the notifiable has registered (see
 * DeviceTokenController) rather than just one — a staff member signed
 * in on both a phone and a tablet should get the alert on both, and
 * same for a customer's phone + tablet. Both `User` and `Customer`
 * (Phase 10) have a `deviceTokens()` relation; anything else (or a
 * notification with no toPush()) is a silent no-op, same reasoning as
 * SmsChannel.
 */
class PushChannel
{
    public function __construct(private PushGateway $gateway)
    {
    }

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toPush')) {
            return;
        }

        if (! $notifiable instanceof User && ! $notifiable instanceof Customer) {
            return;
        }

        $payload = $notification->toPush($notifiable);

        foreach ($notifiable->deviceTokens as $deviceToken) {
            $this->gateway->send($deviceToken->token, $payload['title'], $payload['body'], $payload['data'] ?? []);
        }
    }
}
