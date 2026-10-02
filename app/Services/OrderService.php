<?php

namespace App\Services;

use App\Enums\KitchenTicketStatus;
use App\Enums\MenuItemStatus;
use App\Enums\ModifierSelectionType;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\TableStatus;
use App\Exceptions\FeatureDisabledException;
use App\Exceptions\OrderValidationException;
use App\Models\Branch;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\DeliveryZone;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemReturn;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\RestaurantSetting;
use App\Models\Table;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Everything about turning an order request into a priced, persisted
 * Order + OrderItems + OrderItemModifiers, and about moving an existing
 * order through its status lifecycle. Reuses Product::isAvailableAtBranch()
 * / priceAtBranch() (Phase 2's MenuService) as the one source of truth for
 * "does this exist / what does it cost here" rather than re-deriving
 * either — exactly the seam Phase 2's README said future order creation
 * should use.
 */
class OrderService
{
    public function __construct(
        private OrderNumberService $orderNumbers,
        private FeatureService $features,
        private GeofencingService $geofencing,
        private CouponService $coupons,
        private NotificationService $notifications,
    ) {
    }

    /**
     * `$placedBy` is the staff member who took the order (Phase 3's
     * original use), and is null for a customer's own order (Phase 6) —
     * exactly one of `$placedBy`/`$orderingCustomer` is expected to be
     * set by the caller. `$orderingCustomer`, when given, always wins
     * over any `customer_id` in `$data`: the acting customer is who
     * they're authenticated as, never a client-supplied id.
     */
    public function createOrder(
        Restaurant $restaurant,
        Branch $branch,
        ?User $placedBy,
        array $data,
        ?Customer $orderingCustomer = null,
    ): Order {
        $orderType = OrderType::from($data['order_type']);

        $this->assertOrderTypeAllowed($restaurant, $branch, $orderType);

        $table = null;
        if ($orderType === OrderType::DINE_IN) {
            $table = $this->resolveTable($branch, $data['table_id'] ?? null);
        }

        $deliveryZone = null;
        if ($orderType === OrderType::DELIVERY) {
            if (empty($data['delivery_address'])) {
                throw new OrderValidationException('A delivery address is required for delivery orders.');
            }

            $deliveryZone = $this->resolveDeliveryZone($branch, $data);
        }

        $customer = $orderingCustomer;
        if (! $customer && ! empty($data['customer_id'])) {
            // Customer is not tenant-scoped by a global scope (see its
            // model docblock) — scope explicitly here so a forged
            // cross-tenant customer_id can't be attached to this order.
            $customer = Customer::ofRestaurant($restaurant->id)->findOrFail($data['customer_id']);
        }

        if (empty($data['items']) && empty($data['deals'])) {
            throw new OrderValidationException('An order must have at least one item.');
        }

        $lineItems = array_map(fn ($item) => $this->buildLineItem($branch, $item), $data['items'] ?? []);
        if (! empty($data['deals'])) {
            $lineItems = array_merge($lineItems, app(DealService::class)->expand($restaurant, $branch, $data['deals']));
        }

        $subtotal = round(array_sum(array_column($lineItems, 'line_total')), 2);
        $this->assertMinimumOrderAmount($restaurant, $branch, $subtotal);

        $coupon = null;
        $discountAmount = 0.0;
        if (! empty($data['coupon_code'])) {
            if (! $this->features->isEnabled($restaurant, 'COUPONS')) {
                throw new FeatureDisabledException('COUPONS');
            }

            $coupon = $this->coupons->resolve($restaurant, $data['coupon_code'], $subtotal, $customer);
            $discountAmount = $coupon->discountFor($subtotal);
        }

        $settings = $this->effectiveSettings($restaurant);
        // Until the customer picks cash or card the bill shows the default rate;
        // PaymentService re-works it with the chosen method's rate on first payment.
        $taxRate = $settings->taxRateFor(null);
        $taxAmount = round($subtotal * $taxRate / 100, 2);
        $deliveryFee = $orderType === OrderType::DELIVERY
            ? $this->resolveDeliveryFee($restaurant, $branch, $subtotal, $deliveryZone)
            : 0.0;
        $totalAmount = max(0.0, round($subtotal + $taxAmount + $deliveryFee - $discountAmount, 2));

        $order = DB::transaction(function () use (
            $restaurant, $branch, $placedBy, $data, $orderType, $table, $customer,
            $lineItems, $subtotal, $taxAmount, $taxRate, $deliveryFee, $totalAmount, $deliveryZone,
            $coupon, $discountAmount
        ) {
            $order = Order::create([
                'restaurant_id' => $restaurant->id,
                'branch_id' => $branch->id,
                'table_id' => $table?->id,
                'customer_id' => $customer?->id,
                'placed_by_user_id' => $placedBy?->id,
                'order_number' => $this->orderNumbers->next($restaurant, $branch),
                'order_type' => $orderType->value,
                'status' => OrderStatus::PENDING->value,
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'tax_rate' => $taxRate,
                'fbr_number' => $restaurant->settings?->fbr_number,
                'delivery_fee' => $deliveryFee,
                'discount_amount' => $discountAmount,
                'total_amount' => $totalAmount,
                'delivery_address' => $data['delivery_address'] ?? null,
                'delivery_latitude' => $data['delivery_latitude'] ?? null,
                'delivery_longitude' => $data['delivery_longitude'] ?? null,
                'delivery_zone_id' => $deliveryZone?->id,
                'coupon_id' => $coupon?->id,
                'notes' => $data['notes'] ?? null,
            ]);

            if ($coupon) {
                // Re-validated under a row lock inside redeem() itself — see
                // CouponService's docblock for why this happens twice.
                $this->coupons->redeem($coupon, $order, $customer, $discountAmount);
            }

            // Ticket 1: the kitchen's copy of this order's original items.
            $ticket = $this->openKitchenTicket($order, 1);

            foreach ($lineItems as $line) {
                $orderItem = $order->items()->create([
                    'restaurant_id' => $restaurant->id,
                    'kitchen_ticket_id' => $ticket->id,
                    'product_id' => $line['product']->id,
                    'deal_id' => $line['deal_id'] ?? null,
                    'deal_name' => $line['deal_name'] ?? null,
                    'deal_ref' => $line['deal_ref'] ?? null,
                    'product_name' => $line['product']->name,
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'modifiers_total' => $line['modifiers_total'],
                    'line_total' => $line['line_total'],
                    'notes' => $line['notes'],
                ]);

                foreach ($line['modifiers'] as $selected) {
                    $orderItem->modifiers()->create([
                        'restaurant_id' => $restaurant->id,
                        'modifier_id' => $selected['modifier']->id,
                        'modifier_group_id' => $selected['group']->id,
                        'modifier_name' => $selected['modifier']->name,
                        'price_adjustment' => $selected['modifier']->price_adjustment,
                    ]);
                }
            }

            if ($table) {
                $table->update(['status' => TableStatus::OCCUPIED->value]);
            }

            return $order;
        });

        // Fired after the transaction commits, not from inside it — a
        // notification about an order that then rolled back would be a
        // lie. Covers both the staff (OrderController) and customer
        // (CustomerOrderController) ordering paths, since both funnel
        // through this one method.
        $this->notifications->orderPlaced($order);

        return $order;
    }

    public function updateStatus(Order $order, OrderStatus $next): Order
    {
        if (! $order->status->canTransitionTo($next, $order->order_type)) {
            throw new OrderValidationException(
                "Cannot move an order from {$order->status->value} to {$next->value}."
            );
        }

        $previousStatus = $order->status;

        $order->update(['status' => $next->value]);
        $this->syncKitchenTicketsWithOrder($order, $next);

        if ($next->isTerminal() && $order->table_id) {
            $stillOccupied = Order::where('table_id', $order->table_id)
                ->where('id', '!=', $order->id)
                ->whereIn('status', array_map(
                    fn ($s) => $s->value,
                    array_filter(OrderStatus::cases(), fn ($s) => $s->occupiesTable())
                ))
                ->exists();

            if (! $stillOccupied) {
                $order->table->update(['status' => TableStatus::AVAILABLE->value]);
            }
        }

        $updated = $order->fresh();
        $this->notifications->orderStatusChanged($updated, $previousStatus, $next);

        return $updated;
    }

    /**
     * Moves an order back one kitchen step — the kitchen's "undo" for a
     * mis-tap. Deliberately separate from updateStatus(), which only ever
     * moves forward: this is the one sanctioned way backwards, and only
     * while the order hasn't been picked up yet (see
     * OrderStatus::previousKitchenStep()). None of the kitchen steps are
     * terminal, so the table's status is never affected, and no
     * notification is sent (the steps involved are internal to the
     * kitchen; waiters' screens pick the change up on their next poll).
     */
    public function undoStatus(Order $order): Order
    {
        $previous = $order->status->previousKitchenStep();
        $main = $this->mainKitchenTicket($order);

        if ($previous === null || $main?->status === KitchenTicketStatus::PICKED_UP) {
            throw new OrderValidationException(
                $order->status === OrderStatus::PENDING
                    ? 'This order has not moved yet, so there is nothing to undo.'
                    : 'This order has already been picked up and can no longer be moved back.'
            );
        }

        $before = $order->status;
        $order->update(['status' => $previous->value]);

        // Ticket 1 follows the order back, if it was at the same step.
        if ($main && $main->status->value === $before->value) {
            $main->moveTo(KitchenTicketStatus::from($previous->value));
        }

        return $order->fresh();
    }

    private function openKitchenTicket(Order $order, int $sequence): KitchenTicket
    {
        return KitchenTicket::create([
            'restaurant_id' => $order->restaurant_id,
            'branch_id' => $order->branch_id,
            'order_id' => $order->id,
            'sequence' => $sequence,
            'status' => KitchenTicketStatus::PENDING->value,
        ]);
    }

    public function mainKitchenTicket(Order $order): ?KitchenTicket
    {
        return KitchenTicket::where('order_id', $order->id)->where('sequence', 1)->first();
    }

    /**
     * Where items a waiter adds later should go. While the kitchen hasn't
     * accepted the latest ticket yet, they simply join it — nothing has
     * been cooked, so there's no reason for a second ticket. Once the
     * kitchen has started (or the food has gone out), they go on a new
     * ticket of their own, so the kitchen sees only what's new.
     */
    private function ticketForAddedItems(Order $order): KitchenTicket
    {
        $latest = KitchenTicket::where('order_id', $order->id)->orderByDesc('sequence')->first();

        if ($latest && $latest->status === KitchenTicketStatus::PENDING) {
            return $latest;
        }

        return $this->openKitchenTicket($order, ($latest?->sequence ?? 0) + 1);
    }

    /**
     * Keeps the kitchen tickets consistent when the ORDER's status changes
     * (from the kitchen's main ticket, the waiter app, the admin panel or
     * a rider):
     *  - CONFIRMED / PREPARING / READY: ticket 1 moves to match (never
     *    backwards).
     *  - OUT_FOR_DELIVERY / COMPLETED: anything that was ready is now
     *    picked up. Anything still cooking stays on the kitchen screen —
     *    a waiter may check out while an add-on is still being made.
     *  - CANCELLED: nothing more gets cooked.
     */
    private function syncKitchenTicketsWithOrder(Order $order, OrderStatus $next): void
    {
        $tickets = KitchenTicket::where('order_id', $order->id)->get();

        switch ($next) {
            case OrderStatus::CONFIRMED:
            case OrderStatus::PREPARING:
            case OrderStatus::READY:
                $target = KitchenTicketStatus::from($next->value);
                $main = $tickets->firstWhere('sequence', 1);
                if ($main && $main->status->rank() < $target->rank()) {
                    $main->moveTo($target);
                }
                break;

            case OrderStatus::OUT_FOR_DELIVERY:
            case OrderStatus::COMPLETED:
                foreach ($tickets as $ticket) {
                    if ($ticket->status === KitchenTicketStatus::READY) {
                        $ticket->moveTo(KitchenTicketStatus::PICKED_UP);
                    }
                }
                break;

            case OrderStatus::CANCELLED:
                foreach ($tickets as $ticket) {
                    if ($ticket->status->isInKitchen()) {
                        $ticket->moveTo(KitchenTicketStatus::CANCELLED);
                    }
                }
                break;

            default:
                break;
        }
    }

    /**
     * Rider assignment is deliberately its own method (not folded into
     * updateStatus) — it's an orthogonal piece of state, not a status
     * transition, and a delivery order can be reassigned to a different
     * rider without that being a status change at all.
     */
    public function assignRider(Order $order, User $rider): Order
    {
        if ($order->order_type !== OrderType::DELIVERY) {
            throw new OrderValidationException('Only delivery orders can be assigned a rider.');
        }

        if ($order->status->isTerminal()) {
            throw new OrderValidationException('Cannot assign a rider to a completed or cancelled order.');
        }

        if (! $rider->hasRoleSlug('rider')) {
            throw new OrderValidationException('The selected user does not have the rider role.');
        }

        if (! $rider->canAccessBranch($order->branch_id)) {
            throw new OrderValidationException('The selected rider does not have access to this branch.');
        }

        $order->update(['assigned_rider_id' => $rider->id]);

        $updated = $order->fresh();
        $this->notifications->riderAssigned($updated, $rider);

        return $updated;
    }

    /**
     * A waiter can keep adding to an order right up until checkout
     * (Order::isTerminal() — COMPLETED or CANCELLED); coupon/discount
     * amounts already applied are left untouched (a coupon is re-priced
     * at order time, not renegotiated because more food got added),
     * only subtotal/tax/total move.
     */
    public function addItems(Order $order, array $items, ?User $addedBy = null): Order
    {
        if ($order->status->isTerminal()) {
            throw new OrderValidationException(
                "Cannot add items to an order that is already {$order->status->value}."
            );
        }

        if (empty($items)) {
            throw new OrderValidationException('At least one item is required.');
        }

        $branch = $order->branch;
        $lineItems = array_map(fn ($item) => $this->buildLineItem($branch, $item), $items);
        $addedTotal = round(array_sum(array_column($lineItems, 'line_total')), 2);

        $updated = DB::transaction(function () use ($order, $lineItems, $addedTotal) {
            $ticket = $this->ticketForAddedItems($order);

            foreach ($lineItems as $line) {
                $orderItem = $order->items()->create([
                    'restaurant_id' => $order->restaurant_id,
                    'kitchen_ticket_id' => $ticket->id,
                    'product_id' => $line['product']->id,
                    'product_name' => $line['product']->name,
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'modifiers_total' => $line['modifiers_total'],
                    'line_total' => $line['line_total'],
                    'notes' => $line['notes'],
                ]);

                foreach ($line['modifiers'] as $selected) {
                    $orderItem->modifiers()->create([
                        'restaurant_id' => $order->restaurant_id,
                        'modifier_id' => $selected['modifier']->id,
                        'modifier_group_id' => $selected['group']->id,
                        'modifier_name' => $selected['modifier']->name,
                        'price_adjustment' => $selected['modifier']->price_adjustment,
                    ]);
                }
            }

            $this->repriceOrder($order, round((float) $order->subtotal + $addedTotal, 2));

            return $order->fresh();
        });

        // Deliberately no notification here — OrderPlacedNotification's
        // copy is hard-coded to "New order {number}", which would be a
        // lie for an order the kitchen already has. The kitchen screen
        // polls kitchen tickets and rings its own alarm when a new
        // add-on ticket shows up (see ticketForAddedItems()).

        return $updated;
    }

    /**
     * Records that [$quantity] of [$item] came back, with a reason, and
     * knocks its value off the order's subtotal/tax/total. Allowed on any
     * non-cancelled order, including an already-COMPLETED one — a
     * customer finding a wrong/bad item after paying is exactly when
     * this is needed most; there's no payment gateway integrated to
     * auto-refund the difference (see PaymentService's own docblock), so
     * the order's outstanding balance simply reflects the new, lower
     * total and staff handle the physical cash/card refund themselves.
     */
    public function returnItem(Order $order, OrderItem $item, int $quantity, string $reason, ?User $returnedBy = null): OrderItemReturn
    {
        if ((int) $item->order_id !== $order->id) {
            throw new OrderValidationException('That item does not belong to this order.');
        }

        if ($order->status === OrderStatus::CANCELLED) {
            throw new OrderValidationException('Cannot return items on a cancelled order.');
        }

        $remaining = $item->remainingQuantity();
        if ($quantity < 1 || $quantity > $remaining) {
            throw new OrderValidationException(
                $remaining > 0
                    ? "Return quantity must be between 1 and {$remaining}."
                    : 'This item has already been fully returned.'
            );
        }

        $unitValue = round((float) $item->unit_price + (float) $item->modifiers_total, 2);
        $amount = round($unitValue * $quantity, 2);

        return DB::transaction(function () use ($order, $item, $quantity, $reason, $amount, $returnedBy) {
            $return = OrderItemReturn::create([
                'restaurant_id' => $order->restaurant_id,
                'branch_id' => $order->branch_id,
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'quantity' => $quantity,
                'amount' => $amount,
                'reason' => $reason,
                'returned_by_user_id' => $returnedBy?->id,
            ]);

            $item->increment('returned_quantity', $quantity);

            $this->repriceOrder($order, max(0.0, round((float) $order->subtotal - $amount, 2)));

            return $return;
        });
    }

    /** Recomputes tax/total from a new subtotal and persists all three — the one arithmetic path addItems()/returnItem() both funnel through, so it can never drift from createOrder()'s own math. */
    private function repriceOrder(Order $order, float $newSubtotal): void
    {
        // The rate this order already carries (it may have been fixed by the
        // payment method); older orders without one use today's default.
        $rate = $order->tax_rate !== null
            ? (float) $order->tax_rate
            : $this->effectiveSettings($order->restaurant)->taxRateFor(null);
        $taxAmount = round($newSubtotal * $rate / 100, 2);
        $totalAmount = max(0.0, round($newSubtotal + $taxAmount + (float) $order->delivery_fee - (float) $order->discount_amount, 2));

        $order->update([
            'subtotal' => $newSubtotal,
            'tax_amount' => $taxAmount,
            'total_amount' => $totalAmount,
        ]);
    }

    private function assertOrderTypeAllowed(Restaurant $restaurant, Branch $branch, OrderType $orderType): void
    {
        if (! $this->features->isEnabled($restaurant, $orderType->featureKey())) {
            throw new FeatureDisabledException($orderType->featureKey());
        }

        if (! in_array($orderType->value, $this->resolveOrderTypes($restaurant, $branch), true)) {
            throw new OrderValidationException("{$orderType->value} orders are not available at this branch.");
        }
    }

    /**
     * A branch with no active delivery zones configured at all is treated
     * as "geofencing not set up yet" — no coordinates required, delivery
     * accepted anywhere, exactly Phase 3's original behavior. Once a
     * branch has at least one active zone, coordinates become required
     * and the address must actually fall inside one of them.
     */
    private function resolveDeliveryZone(Branch $branch, array $data): ?DeliveryZone
    {
        if (! $this->geofencing->branchHasZonesConfigured($branch)) {
            return null;
        }

        if (! isset($data['delivery_latitude'], $data['delivery_longitude'])) {
            throw new OrderValidationException('Delivery coordinates are required to check delivery availability for this branch.');
        }

        $zone = $this->geofencing->resolveZone(
            $branch,
            (float) $data['delivery_latitude'],
            (float) $data['delivery_longitude']
        );

        if (! $zone) {
            throw new OrderValidationException('This delivery address is outside the delivery area for this branch.');
        }

        return $zone;
    }

    private function resolveTable(Branch $branch, ?int $tableId): Table
    {
        if (! $tableId) {
            throw new OrderValidationException('A table is required for dine-in orders.');
        }

        $table = Table::where('branch_id', $branch->id)->find($tableId);

        if (! $table) {
            // Exists in this tenant but not this branch, or doesn't exist at
            // all — either way, not something the order can be seated at.
            throw new OrderValidationException('That table was not found for this branch.');
        }

        if (! $table->status->canBeAssignedToNewOrder()) {
            throw new OrderValidationException("Table {$table->table_number} is not available ({$table->status->value}).");
        }

        return $table;
    }

    /** @return array{product: Product, quantity: int, unit_price: float, modifiers_total: float, line_total: float, notes: ?string, modifiers: array} */
    private function buildLineItem(Branch $branch, array $item): array
    {
        $product = Product::with('modifierGroups.modifiers')->findOrFail($item['product_id']);

        if (! $product->isAvailableAtBranch($branch->id)) {
            throw new OrderValidationException("{$product->name} is not available at this branch.");
        }

        $quantity = (int) $item['quantity'];
        $unitPrice = $product->priceAtBranch($branch->id);
        $requestedIds = array_map('intval', $item['modifier_ids'] ?? []);

        $selected = [];
        $modifiersTotalPerUnit = 0.0;
        $allAttachedIds = [];

        foreach ($product->modifierGroups as $group) {
            // Only an ACTIVE modifier can be selected — an inactive one is
            // treated the same as one that was never attached at all.
            $activeModifiers = $group->modifiers->where('status', MenuItemStatus::ACTIVE);
            $groupModifierIds = $activeModifiers->pluck('id')->all();
            $allAttachedIds = array_merge($allAttachedIds, $groupModifierIds);

            $selectedForGroup = array_values(array_intersect($requestedIds, $groupModifierIds));
            $count = count($selectedForGroup);

            if ($group->selection_type === ModifierSelectionType::SINGLE && $count > 1) {
                throw new OrderValidationException("'{$group->name}' only allows one selection for {$product->name}.");
            }

            if ($group->max_selections !== null && $count > $group->max_selections) {
                throw new OrderValidationException("'{$group->name}' allows at most {$group->max_selections} selection(s) for {$product->name}.");
            }

            $requiredMin = $group->is_required ? max(1, $group->min_selections) : $group->min_selections;
            if ($count < $requiredMin && ($group->is_required || $count > 0)) {
                throw new OrderValidationException("'{$group->name}' requires at least {$requiredMin} selection(s) for {$product->name}.");
            }

            foreach ($selectedForGroup as $modifierId) {
                $modifier = $activeModifiers->firstWhere('id', $modifierId);
                $modifiersTotalPerUnit += (float) $modifier->price_adjustment;
                $selected[] = ['modifier' => $modifier, 'group' => $group];
            }
        }

        if (! empty(array_diff($requestedIds, $allAttachedIds))) {
            throw new OrderValidationException("Invalid modifier selection for {$product->name}.");
        }

        $lineTotal = round(($unitPrice + $modifiersTotalPerUnit) * $quantity, 2);

        return [
            'product' => $product,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'modifiers_total' => round($modifiersTotalPerUnit, 2),
            'line_total' => $lineTotal,
            'notes' => $item['notes'] ?? null,
            'modifiers' => $selected,
        ];
    }

    private function assertMinimumOrderAmount(Restaurant $restaurant, Branch $branch, float $subtotal): void
    {
        $min = $this->resolveMinOrderAmount($restaurant, $branch);

        if ($subtotal < $min) {
            throw new OrderValidationException("Order subtotal must be at least {$min}.");
        }
    }

    /**
     * What delivering to a point would cost and whether it is possible at
     * all — the same zone + fee rules placing a DELIVERY order applies,
     * exposed so an app can show the real fee (and refuse an address
     * outside the delivery area) before the customer ever taps "Place order".
     *
     * @return array{deliverable: bool, reason: ?string, zone: ?array, fee: ?float, free_delivery_threshold: ?float, zones_configured: bool}
     */
    public function deliveryQuote(Restaurant $restaurant, Branch $branch, ?float $latitude, ?float $longitude, float $subtotal): array
    {
        $zonesConfigured = $this->geofencing->branchHasZonesConfigured($branch);
        $zone = null;
        $deliverable = true;
        $reason = null;

        if ($zonesConfigured) {
            if ($latitude === null || $longitude === null) {
                $deliverable = false;
                $reason = 'LOCATION_REQUIRED';
            } else {
                $zone = $this->geofencing->resolveZone($branch, $latitude, $longitude);
                if (! $zone) {
                    $deliverable = false;
                    $reason = 'OUTSIDE_DELIVERY_AREA';
                }
            }
        }

        return [
            'deliverable' => $deliverable,
            'reason' => $reason,
            'zone' => $zone ? ['id' => $zone->id, 'name' => $zone->name] : null,
            'fee' => $deliverable ? $this->resolveDeliveryFee($restaurant, $branch, $subtotal, $zone) : null,
            'free_delivery_threshold' => $this->resolveFreeDeliveryThreshold($restaurant, $branch),
            'zones_configured' => $zonesConfigured,
        ];
    }

    /**
     * Free-delivery-threshold wins outright regardless of zone — a big
     * enough order is free to deliver no matter which zone it lands in.
     * Below that threshold: the zone's own override (if it set one) beats
     * the branch/restaurant default, same override-wins pattern as every
     * other setting in this service.
     */
    private function resolveDeliveryFee(Restaurant $restaurant, Branch $branch, float $subtotal, ?DeliveryZone $zone): float
    {
        $threshold = $this->resolveFreeDeliveryThreshold($restaurant, $branch);

        if ($threshold !== null && $subtotal >= $threshold) {
            return 0.0;
        }

        if ($zone?->delivery_fee_override !== null) {
            return (float) $zone->delivery_fee_override;
        }

        return (float) ($restaurant->settings?->delivery_fee ?? 0);
    }

    /** order_types: branch override (if set) wins outright, else the restaurant default, else a safe fallback. */
    private function resolveOrderTypes(Restaurant $restaurant, Branch $branch): array
    {
        $branchTypes = $branch->settings?->order_types;
        if ($branchTypes !== null) {
            return $branchTypes;
        }

        return $restaurant->settings?->order_types ?? ['DINE_IN', 'TAKEAWAY'];
    }

    private function resolveMinOrderAmount(Restaurant $restaurant, Branch $branch): float
    {
        $branchValue = $branch->settings?->min_order_amount;

        return (float) ($branchValue ?? $restaurant->settings?->min_order_amount ?? 0);
    }

    private function resolveFreeDeliveryThreshold(Restaurant $restaurant, Branch $branch): ?float
    {
        $branchValue = $branch->settings?->free_delivery_threshold;
        if ($branchValue !== null) {
            return (float) $branchValue;
        }

        $restaurantValue = $restaurant->settings?->free_delivery_threshold;

        return $restaurantValue !== null ? (float) $restaurantValue : null;
    }

    private function effectiveSettings(Restaurant $restaurant): RestaurantSetting
    {
        return $restaurant->settings ?? new RestaurantSetting([
            'tax_enabled' => false,
            'tax_percentage' => 0,
            'delivery_fee' => 0,
        ]);
    }
}
