<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\FeatureDisabledException;
use App\Exceptions\PaymentException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * No real payment gateway is integrated in this phase — there's no
 * gateway account to key off of yet. Instead this models the API shape
 * a real one would plug into: CASH/CARD settle immediately (a staff
 * member is physically handling the money or terminal right now),
 * ONLINE starts PENDING and needs a separate confirm() call, standing
 * in for a gateway's webhook/redirect callback. Swapping in a real
 * gateway later means calling confirm()/fail() from its webhook handler
 * instead of a client-triggered endpoint — this service's contract
 * doesn't need to change.
 */
class PaymentService
{
    public function __construct(
        private FeatureService $features,
        private NotificationService $notifications,
    ) {
    }

    public function record(Order $order, array $data, ?User $recordedBy = null): Payment
    {
        $method = PaymentMethod::from($data['method']);

        if ($method->requiresOnlinePaymentsFeature() && ! $this->features->isEnabled($order->restaurant, 'ONLINE_PAYMENTS')) {
            throw new FeatureDisabledException('ONLINE_PAYMENTS');
        }

        if ($order->status === OrderStatus::CANCELLED) {
            throw new PaymentException('Cannot record a payment against a cancelled order.');
        }

        $amount = round((float) $data['amount'], 2);
        if ($amount <= 0) {
            throw new PaymentException('Payment amount must be greater than zero.');
        }

        // The tiny epsilon absorbs float rounding noise from summing prior
        // payments (e.g. an outstanding balance that's really 4.00 coming
        // back as 3.9999999998), without being large enough to let a real
        // one-cent overpayment slip through.
        $outstanding = $order->outstandingBalance();
        if ($amount > $outstanding + 0.005) {
            throw new PaymentException("Payment amount ({$amount}) exceeds the order's outstanding balance ({$outstanding}).");
        }

        $status = $method->settlesImmediately() ? PaymentStatus::PAID : PaymentStatus::PENDING;

        $payment = Payment::create([
            'restaurant_id' => $order->restaurant_id,
            'branch_id' => $order->branch_id,
            'order_id' => $order->id,
            'method' => $method->value,
            'status' => $status->value,
            'amount' => $amount,
            'transaction_reference' => $data['transaction_reference']
                ?? ($method === PaymentMethod::ONLINE ? (string) Str::uuid() : null),
            'recorded_by_user_id' => $recordedBy?->id,
            'notes' => $data['notes'] ?? null,
            'paid_at' => $status === PaymentStatus::PAID ? now() : null,
        ]);

        // CASH/CARD settle immediately, right here; ONLINE settles later
        // via confirm() below, which fires the same notification there.
        if ($status === PaymentStatus::PAID) {
            $this->notifications->paymentReceived($payment);
        }

        return $payment;
    }

    /** Stands in for a gateway's success webhook/redirect callback. */
    public function confirm(Payment $payment): Payment
    {
        if ($payment->method !== PaymentMethod::ONLINE) {
            throw new PaymentException('Only an online payment can be confirmed.');
        }

        if ($payment->status !== PaymentStatus::PENDING) {
            throw new PaymentException("Only a pending payment can be confirmed (this one is {$payment->status->value}).");
        }

        $payment->update(['status' => PaymentStatus::PAID->value, 'paid_at' => now()]);
        $payment = $payment->fresh();

        $this->notifications->paymentReceived($payment);

        return $payment;
    }

    /** Stands in for a gateway's failure webhook. */
    public function fail(Payment $payment): Payment
    {
        if ($payment->status !== PaymentStatus::PENDING) {
            throw new PaymentException("Only a pending payment can be marked failed (this one is {$payment->status->value}).");
        }

        $payment->update(['status' => PaymentStatus::FAILED->value]);

        return $payment->fresh();
    }

    public function refund(Payment $payment): Payment
    {
        if ($payment->status !== PaymentStatus::PAID) {
            throw new PaymentException("Only a paid payment can be refunded (this one is {$payment->status->value}).");
        }

        $payment->update(['status' => PaymentStatus::REFUNDED->value, 'refunded_at' => now()]);

        return $payment->fresh();
    }
}
