<?php

namespace App\Notifications;

use App\Models\Payment;
use App\Notifications\Concerns\HasChannelPreferences;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The one genuinely new event this phase adds — order.placed/
 * status_changed/rider_assigned all existed since Phase 8. Fired by
 * PaymentService whenever a payment settles as PAID, whether that's
 * immediate (CASH/CARD in record()) or via a later confirm() call for
 * ONLINE. Sent to staff with `payments.view` access to the order's
 * branch, not the whole `orders.view` audience — someone who can see
 * orders but not payments shouldn't get paged every time money moves.
 */
class PaymentReceivedNotification extends Notification
{
    use HasChannelPreferences;

    public function __construct(private Payment $payment)
    {
    }

    public function via(object $notifiable): array
    {
        return $this->channelsFor($notifiable, 'payment.received');
    }

    public function toArray(object $notifiable): array
    {
        $order = $this->payment->order;

        return [
            'type' => 'payment.received',
            'title' => "Payment received for order {$order->order_number}",
            'body' => sprintf('%s payment of %s.', $this->payment->method->value, number_format((float) $this->payment->amount, 2)),
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'branch_id' => $this->payment->branch_id,
            'payment_id' => $this->payment->id,
            'amount' => (float) $this->payment->amount,
            'method' => $this->payment->method->value,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $order = $this->payment->order;

        return (new MailMessage)
            ->subject("Payment received for order {$order->order_number}")
            ->line(sprintf(
                'A %s payment of %s was received for order %s.',
                $this->payment->method->value,
                number_format((float) $this->payment->amount, 2),
                $order->order_number,
            ));
    }

    public function toSms(object $notifiable): string
    {
        return sprintf(
            'Payment received: %s for order %s.',
            number_format((float) $this->payment->amount, 2),
            $this->payment->order->order_number,
        );
    }

    /** @return array{title: string, body: string, data: array} */
    public function toPush(object $notifiable): array
    {
        $order = $this->payment->order;

        return [
            'title' => "Payment received for order {$order->order_number}",
            'body' => sprintf('%s payment of %s.', $this->payment->method->value, number_format((float) $this->payment->amount, 2)),
            'data' => ['order_id' => $order->id, 'payment_id' => $this->payment->id],
        ];
    }
}
