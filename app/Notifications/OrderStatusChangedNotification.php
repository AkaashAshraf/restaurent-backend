<?php

namespace App\Notifications;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Notifications\Concerns\HasChannelPreferences;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Only fired for the "meaningful handoff" statuses (see
 * NotificationService::STATUS_NOTIFICATION_WORTHY) — READY,
 * OUT_FOR_DELIVERY, COMPLETED, CANCELLED — not every internal step
 * (PENDING -> CONFIRMED -> PREPARING). Nobody needs a notification for
 * every link in the kitchen's own workflow; they need to know when an
 * order is ready to hand off, out the door, done, or dead. Since
 * Phase 10, this also reaches the order's own customer, if it has one
 * (see NotificationService::orderStatusChanged()) — the exact same set
 * of statuses matters to them, arguably more so.
 *
 * See OrderPlacedNotification's docblock for the mail/sms/push opt-in
 * story and the per-audience body text — identical reasoning here.
 */
class OrderStatusChangedNotification extends Notification
{
    use HasChannelPreferences;

    public function __construct(
        private Order $order,
        private OrderStatus $previous,
        private OrderStatus $next,
    ) {
    }

    public function via(object $notifiable): array
    {
        return $this->channelsFor($notifiable, 'order.status_changed');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'order.status_changed',
            'title' => "Order {$this->order->order_number} is now {$this->next->value}",
            'body' => $this->body($notifiable),
            'order_id' => $this->order->id,
            'order_number' => $this->order->order_number,
            'branch_id' => $this->order->branch_id,
            'previous_status' => $this->previous->value,
            'status' => $this->next->value,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Order {$this->order->order_number} is now {$this->next->value}")
            ->line($this->body($notifiable));
    }

    public function toSms(object $notifiable): string
    {
        return "Order {$this->order->order_number} is now {$this->next->value}.";
    }

    /** @return array{title: string, body: string, data: array} */
    public function toPush(object $notifiable): array
    {
        return [
            'title' => "Order {$this->order->order_number} is now {$this->next->value}",
            'body' => $this->body($notifiable),
            'data' => ['order_id' => $this->order->id, 'status' => $this->next->value],
        ];
    }

    private function body(object $notifiable): string
    {
        if ($notifiable instanceof Customer) {
            return "Your order is now {$this->next->value}.";
        }

        return "Moved from {$this->previous->value} to {$this->next->value}.";
    }
}
