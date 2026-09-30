<?php

namespace App\Notifications\Channels;

use App\Contracts\SmsGateway;
use Illuminate\Notifications\Notification;

/**
 * Laravel resolves a channel returned from a notification's via() as a
 * class name through the container and calls send($notifiable,
 * $notification) on it — the same contract the built-in 'mail'/
 * 'database' channels follow, just implemented here instead of by the
 * framework. Only notifiables with a phone on file receive anything,
 * and nothing throws if it's missing or the notification has no
 * toSms() — a misconfigured recipient should never fail the request
 * that triggered the notification.
 */
class SmsChannel
{
    public function __construct(private SmsGateway $gateway)
    {
    }

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toSms')) {
            return;
        }

        $to = $notifiable->phone ?? null;
        if (! $to) {
            return;
        }

        $this->gateway->send($to, $notification->toSms($notifiable));
    }
}
