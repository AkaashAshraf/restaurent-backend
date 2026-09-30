<?php

namespace App\Notifications;

use App\Models\Order;
use App\Notifications\Concerns\HasChannelPreferences;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent only to the one rider just assigned — not the whole branch
 * staff. See OrderPlacedNotification's docblock for the mail/sms/push
 * opt-in story; a rider on the road is arguably the best candidate for
 * actually turning sms/push on, but that's their choice to make via
 * NotificationPreferenceController, not something this class assumes.
 */
class OrderRiderAssignedNotification extends Notification
{
    use HasChannelPreferences;

    public function __construct(private Order $order)
    {
    }

    public function via(object $notifiable): array
    {
        return $this->channelsFor($notifiable, 'order.rider_assigned');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'order.rider_assigned',
            'title' => "You've been assigned order {$this->order->order_number}",
            'body' => 'Pick it up for delivery.',
            'order_id' => $this->order->id,
            'order_number' => $this->order->order_number,
            'branch_id' => $this->order->branch_id,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("You've been assigned order {$this->order->order_number}")
            ->line('Pick it up for delivery.');
    }

    public function toSms(object $notifiable): string
    {
        return "You've been assigned order {$this->order->order_number}. Pick it up for delivery.";
    }

    /** @return array{title: string, body: string, data: array} */
    public function toPush(object $notifiable): array
    {
        return [
            'title' => "You've been assigned order {$this->order->order_number}",
            'body' => 'Pick it up for delivery.',
            'data' => ['order_id' => $this->order->id],
        ];
    }
}
