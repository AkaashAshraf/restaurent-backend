<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Exceptions\OrderValidationException;
use App\Exceptions\PermissionDeniedException;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemReturn;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PermissionService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(
        private OrderService $orders,
        private PermissionService $permissions,
    ) {
    }

    public function index(Request $request)
    {
        $user = $request->user();
        // placedBy (id + name only): lets the kitchen see which waiter an
        // order belongs to. Null for a customer's own online order.
        // kitchenTickets (summary only): lets the waiter app see each round's
        // kitchen progress — "Add-on 2 is ready", "picked up" — on the list.
        $query = Order::query()->with(
            'table', 'customer', 'placedBy:id,name',
            'kitchenTickets:id,order_id,sequence,status,ready_at,picked_up_at'
        );

        // Opt-in: the kitchen display shows every ticket's items at a
        // glance, so it asks for them in the same request instead of one
        // extra GET /orders/{id} per ticket. Everyone else keeps the
        // lighter list payload.
        if ($request->boolean('with_items')) {
            $query->with('items.modifiers');
        }

        if (! $user->is_super_admin && ! $user->hasRestaurantWideAccess()) {
            $query->whereIn('branch_id', $user->accessibleBranchIds() ?: [0]);
        }

        // A rider only ever sees orders assigned to them, on top of (not
        // instead of) the branch restriction above — a rider who somehow
        // loses branch access loses order visibility too.
        if ($user->hasRoleSlug('rider')) {
            $query->where('assigned_rider_id', $user->id);
        }

        if ($branchId = $request->query('branch_id')) {
            $query->where('branch_id', $branchId);
        }
        if ($status = $request->query('status')) {
            // Kitchen/waiter queue views need "everything still active",
            // i.e. several statuses at once: ?status=PENDING,CONFIRMED,PREPARING.
            $statuses = array_values(array_filter(array_map('trim', explode(',', $status))));
            $query->whereIn('status', $statuses);
        }
        if ($orderType = $request->query('order_type')) {
            $query->where('order_type', $orderType);
        }

        // Matches the plain-array convention every other list endpoint in
        // this app uses (branches/categories/products); pagination for
        // order history at real volume is a reasonable future addition,
        // not needed to prove this endpoint out.
        return ApiResponse::success($query->latest()->get());
    }

    /**
     * branch_id is a request body field, not a route segment — same
     * `branch.access` middleware fallback-to-input pattern as
     * TableController::store (see routes/api.php).
     */
    public function store(Request $request)
    {
        $restaurant = $request->user()->restaurant;

        // A waiter only takes orders at the table, so theirs are always
        // dine-in: no need to send an order type, and no other one is accepted.
        $roles = $request->user()->roles;
        if ($roles->isNotEmpty() && $roles->every(fn ($role) => $role->slug === 'waiter')) {
            if ($request->filled('order_type') && $request->input('order_type') !== 'DINE_IN') {
                throw new OrderValidationException('Waiters can only place dine-in orders.');
            }
            $request->merge(['order_type' => 'DINE_IN']);
        }

        $data = $request->validate([
            'branch_id' => ['required', 'integer'],
            'order_type' => ['required', 'in:DINE_IN,TAKEAWAY,DELIVERY'],
            'table_id' => ['nullable', 'integer'],
            'customer_id' => ['nullable', 'integer'],
            'delivery_address' => ['nullable', 'string'],
            'delivery_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'delivery_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'coupon_code' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.modifier_ids' => ['nullable', 'array'],
            'items.*.modifier_ids.*' => ['integer'],
            'items.*.notes' => ['nullable', 'string'],
        ]);

        $branch = Branch::findOrFail($data['branch_id']);

        $order = $this->orders->createOrder($restaurant, $branch, $request->user(), $data);

        AuditLog::create([
            'restaurant_id' => $restaurant->id,
            'branch_id' => $branch->id,
            'user_id' => $request->user()->id,
            'action' => 'order.created',
            'subject_type' => Order::class,
            'subject_id' => $order->id,
            'changes' => ['new' => ['order_number' => $order->order_number, 'total_amount' => (string) $order->total_amount]],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return ApiResponse::success($order->load('items.modifiers', 'table', 'deliveryZone', 'coupon')->append('tax_options'), 201);
    }

    /**
     * Adds line items to an order that hasn't been checked out yet
     * (branch_id is implied by the order, not a body field, since this
     * one order already has a branch — unlike store()).
     */
    public function addItems(Request $request, int $order)
    {
        $order = Order::findOrFail($order);
        $this->assertBranchAccess($request, $order->branch_id);

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.modifier_ids' => ['nullable', 'array'],
            'items.*.modifier_ids.*' => ['integer'],
            'items.*.notes' => ['nullable', 'string'],
        ]);

        $before = ['subtotal' => (string) $order->subtotal, 'total_amount' => (string) $order->total_amount];

        $updated = $this->orders->addItems($order, $data['items'], $request->user());

        AuditLog::create([
            'restaurant_id' => $updated->restaurant_id,
            'branch_id' => $updated->branch_id,
            'user_id' => $request->user()->id,
            'action' => 'order.items_added',
            'subject_type' => Order::class,
            'subject_id' => $updated->id,
            'changes' => ['old' => $before, 'new' => [
                'subtotal' => (string) $updated->subtotal, 'total_amount' => (string) $updated->total_amount,
            ]],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return ApiResponse::success($updated->load('items.modifiers', 'table'), 201);
    }

    /**
     * `item` is scoped to `order` by hand below rather than route-model-
     * bound — a route-model-bound OrderItem would resolve globally
     * (tenant-scoped, but not order-scoped), and a mismatched pair here
     * is a real bug (wrong item on this order), not a 404.
     */
    public function returnItem(Request $request, int $order, int $item)
    {
        $order = Order::findOrFail($order);
        $this->assertBranchAccess($request, $order->branch_id);
        $orderItem = OrderItem::where('order_id', $order->id)->findOrFail($item);

        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $return = $this->orders->returnItem($order, $orderItem, $data['quantity'], $data['reason'], $request->user());

        AuditLog::create([
            'restaurant_id' => $order->restaurant_id,
            'branch_id' => $order->branch_id,
            'user_id' => $request->user()->id,
            'action' => 'order.item_returned',
            'subject_type' => OrderItemReturn::class,
            'subject_id' => $return->id,
            'changes' => ['new' => [
                'order_item_id' => $orderItem->id, 'quantity' => $return->quantity,
                'amount' => (string) $return->amount, 'reason' => $return->reason,
            ]],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return ApiResponse::success($order->fresh()->load('items.modifiers', 'items.returns', 'table'), 201);
    }

    public function show(Request $request, int $order)
    {
        $order = Order::findOrFail($order);
        $this->assertBranchAccess($request, $order->branch_id);

        return ApiResponse::success($order->load(
            'items.modifiers', 'items.returns', 'table', 'customer', 'placedBy', 'deliveryZone',
            'assignedRider', 'coupon', 'payments', 'kitchenTickets:id,order_id,sequence,status,ready_at,picked_up_at'
        )->append('tax_options'));
    }

    public function updateStatus(Request $request, int $order)
    {
        $order = Order::findOrFail($order);
        $this->assertBranchAccess($request, $order->branch_id);

        $data = $request->validate([
            'status' => ['required', 'in:PENDING,CONFIRMED,PREPARING,READY,OUT_FOR_DELIVERY,COMPLETED,CANCELLED'],
        ]);

        $before = $order->status->value;
        $updated = $this->orders->updateStatus($order, OrderStatus::from($data['status']));

        AuditLog::create([
            'restaurant_id' => $order->restaurant_id,
            'branch_id' => $order->branch_id,
            'user_id' => $request->user()->id,
            'action' => 'order.status_changed',
            'subject_type' => Order::class,
            'subject_id' => $order->id,
            'changes' => ['old' => $before, 'new' => $data['status']],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return ApiResponse::success($updated->load('items.modifiers', 'table'));
    }

    /**
     * The kitchen's "undo": back one step (READY -> PREPARING ->
     * CONFIRMED -> PENDING), only until the order is picked up. See
     * OrderService::undoStatus().
     */
    public function undoStatus(Request $request, int $order)
    {
        $order = Order::findOrFail($order);
        $this->assertBranchAccess($request, $order->branch_id);

        $before = $order->status->value;
        $updated = $this->orders->undoStatus($order);

        AuditLog::create([
            'restaurant_id' => $order->restaurant_id,
            'branch_id' => $order->branch_id,
            'user_id' => $request->user()->id,
            'action' => 'order.status_undone',
            'subject_type' => Order::class,
            'subject_id' => $order->id,
            'changes' => ['old' => $before, 'new' => $updated->status->value],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return ApiResponse::success($updated->load('items.modifiers', 'table', 'placedBy:id,name'));
    }

    /**
     * `rider_id` is a body field, not a route segment (mirrors the
     * `branch.access` fallback pattern used for tables/orders creation),
     * so it's looked up here rather than route-model-bound.
     */
    public function assignRider(Request $request, int $order)
    {
        $order = Order::findOrFail($order);
        $this->assertBranchAccess($request, $order->branch_id);

        $data = $request->validate([
            'rider_id' => ['required', 'integer'],
        ]);

        $rider = User::ofRestaurant($request->user()->restaurant_id)->findOrFail($data['rider_id']);

        $before = $order->assigned_rider_id;
        $updated = $this->orders->assignRider($order, $rider);

        AuditLog::create([
            'restaurant_id' => $order->restaurant_id,
            'branch_id' => $order->branch_id,
            'user_id' => $request->user()->id,
            'action' => 'order.rider_assigned',
            'subject_type' => Order::class,
            'subject_id' => $order->id,
            'changes' => ['old' => $before, 'new' => $rider->id],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return ApiResponse::success($updated->load('items.modifiers', 'table', 'assignedRider'));
    }

    /**
     * Same check `EnsureBranchAccess` performs, applied manually: the route
     * parameter here is an order id, not a branch id, so the middleware
     * has nothing to resolve a branch from on show/updateStatus.
     */
    private function assertBranchAccess(Request $request, int $branchId): void
    {
        if (! $this->permissions->userCanAccessBranch($request->user(), $branchId)) {
            throw new PermissionDeniedException('You do not have access to this branch.');
        }
    }
}
