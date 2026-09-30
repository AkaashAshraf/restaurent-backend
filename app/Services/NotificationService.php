<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\UserStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\OrderPlacedNotification;
use App\Notifications\OrderRiderAssignedNotification;
use App\Notifications\OrderStatusChangedNotification;
use App\Notifications\PaymentReceivedNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Lives inside `OrderService`'s three call sites (create/updateStatus/
 * assignRider) rather than duplicated across `OrderController` and
 * `CustomerOrderController` — both controllers funnel through the same
 * `OrderService` methods, so firing notifications there covers a
 * staff-placed and a customer-placed order identically, the same
 * "single source of truth" instinct as FeatureService/PermissionService.
 */
class NotificationService
{
    /**
     * Only these transitions are worth a notification — see
     * OrderStatusChangedNotification's docblock for why the others
     * (PENDING -> CONFIRMED -> PREPARING) are deliberately silent.
     */
    private const STATUS_NOTIFICATION_WORTHY = ['READY', 'OUT_FOR_DELIVERY', 'COMPLETED', 'CANCELLED'];

    public function __construct(private PermissionService $permissions)
    {
    }

    public function orderPlaced(Order $order): void
    {
        $notification = new OrderPlacedNotification($order);

        Notification::send($this->staffVisibleTo($order), $notification);

        // Phase 10: the same event, reused as-is (its body text already
        // branches per-audience) — a staff-placed order with a known
        // customer_id notifies that customer too, exactly like a
        // customer-placed one does.
        if ($order->customer) {
            $order->customer->notify($notification);
        }
    }

    public function orderStatusChanged(Order $order, OrderStatus $previous, OrderStatus $next): void
    {
        if (! in_array($next->value, self::STATUS_NOTIFICATION_WORTHY, true)) {
            return;
        }

        $notification = new OrderStatusChangedNotification($order, $previous, $next);

        Notification::send($this->staffVisibleTo($order), $notification);

        if ($order->customer) {
            $order->customer->notify($notification);
        }
    }

    /** Only the rider just assigned — not the whole branch's staff. */
    public function riderAssigned(Order $order, User $rider): void
    {
        $rider->notify(new OrderRiderAssignedNotification($order));
    }

    /**
     * Fired by PaymentService whenever a payment settles as PAID.
     * Audience is `payments.view`, not `orders.view` — someone who can
     * see orders but not payments shouldn't get paged every time money
     * moves.
     */
    public function paymentReceived(Payment $payment): void
    {
        Notification::send($this->staffVisibleTo($payment->order, 'payments.view'), new PaymentReceivedNotification($payment));
    }

    /**
     * Every active staff member who can both access this branch and has
     * the given permission — the exact same two checks
     * `OrderController::index()` itself applies to decide what a given
     * user can see, just run against every user instead of the
     * requester, and parameterized so `payments.view` can reuse it.
     * Restaurant-sized user lists make an in-PHP filter fine here; this
     * isn't a hot path.
     *
     * @return Collection<int, User>
     */
    private function staffVisibleTo(Order $order, string $permission = 'orders.view'): Collection
    {
        return User::where('restaurant_id', $order->restaurant_id)
            ->where('status', UserStatus::ACTIVE->value)
            ->get()
            ->filter(fn (User $user) => $this->permissions->userCanAccessBranch($user, $order->branch_id)
                && $this->permissions->userHasPermission($user, $permission));
    }
}
