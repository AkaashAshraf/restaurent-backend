<?php

namespace Database\Seeders\Demo;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\NotificationService;

/**
 * Stands in for NotificationService while demo history is generated, so
 * thousands of back-dated orders don't email, push or write a
 * notification row to every staff member (and don't slow the seeder down).
 */
class SilentNotifications extends NotificationService
{
    public function __construct()
    {
    }

    public function orderPlaced(Order $order): void
    {
    }

    public function orderStatusChanged(Order $order, OrderStatus $previous, OrderStatus $next): void
    {
    }

    public function riderAssigned(Order $order, User $rider): void
    {
    }

    public function paymentReceived(Payment $payment): void
    {
    }
}
