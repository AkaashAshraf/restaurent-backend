<?php

namespace App\Notifications;

use App\Models\Customer;
use App\Models\Order;
use App\Notifications\Concerns\HasChannelPreferences;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to every staff member with `orders.view` access to the order's
 * branch (kitchen/waiter/branch-manager/owner/admin) — see
 * NotificationService::staffVisibleTo() — and, since Phase 10, to the
 * order's own customer too, if it has one (see
 * NotificationService::orderPlaced()). Deliberately does not implement
 * `ShouldQueue`: this app has no queue worker guaranteed to be running,
 * so the notification is sent synchronously, in the same request that
 * created the order, exactly like every other side effect in this
 * codebase (AuditLog::create(), etc.).
 *
 * `database` is always sent; mail/sms/push are added per-recipient by
 * HasChannelPreferences based on that recipient's own opt-in choices
 * (see NotificationPreferenceService) — nobody gets a real email/text/
 * push for this unless they've explicitly turned it on. The body text
 * itself is personalized per audience: a customer reads "Your order
 * ...", staff read "A new order ..." — the same underlying event, two
 * different vantage points on it.
 */
class OrderPlacedNotification extends Notification
{
    use HasChannelPreferences;

    public function __construct(private Order $order)
    {
    }

    public function via(object $notifiable): array
    {
        return $this->channelsFor($notifiable, 'order.placed');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'order.placed',
            'title' => "New order {$this->order->order_number}",
            'body' => $this->body($notifiable),
            'order_id' => $this->order->id,
            'order_number' => $this->order->order_number,
            'branch_id' => $this->order->branch_id,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("New order {$this->order->order_number}")
            ->line($this->body($notifiable));
    }

    public function toSms(object $notifiable): string
    {
        return "New order {$this->order->order_number}: {$this->body($notifiable)}";
    }

    /** @return array{title: string, body: string, data: array} */
    public function toPush(object $notifiable): array
    {
        return [
            'title' => "New order {$this->order->order_number}",
            'body' => $this->body($notifiable),
            'data' => ['order_id' => $this->order->id],
        ];
    }

    private function body(object $notifiable): string
    {
        return sprintf(
            '%s %s order for %s %s placed.',
            $notifiable instanceof Customer ? 'Your' : 'A new',
            $this->order->order_type->value,
            number_format((float) $this->order->total_amount, 2),
            $notifiable instanceof Customer ? 'has been' : 'was',
        );
    }
}
