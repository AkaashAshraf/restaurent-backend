<?php

namespace App\Notifications;

use App\Models\Deal;
use App\Notifications\Concerns\HasChannelPreferences;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "New deal" announcement the restaurant sends to its customers. Always lands
 * in the in-app inbox (and lights the bell); push is on unless the customer
 * switched it off, mail/sms only if they opted in (see NotificationPreferenceService).
 */
class DealAnnouncementNotification extends Notification
{
    use HasChannelPreferences;

    public function __construct(private Deal $deal, private string $body)
    {
    }

    public function via(object $notifiable): array
    {
        return $this->channelsFor($notifiable, 'deal.announced');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'deal.announced',
            'title' => "New deal: {$this->deal->name}",
            'body' => $this->body,
            'deal_id' => $this->deal->id,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject("New deal: {$this->deal->name}")->line($this->body);
    }

    public function toSms(object $notifiable): string
    {
        return "New deal: {$this->deal->name}. {$this->body}";
    }

    /** @return array{title: string, body: string, data: array} */
    public function toPush(object $notifiable): array
    {
        return [
            'title' => "New deal: {$this->deal->name}",
            'body' => $this->body,
            'data' => ['deal_id' => $this->deal->id],
        ];
    }
}
