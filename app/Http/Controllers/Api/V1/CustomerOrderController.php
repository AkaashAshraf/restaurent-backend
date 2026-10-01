<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CustomerAddress;
use App\Models\Order;
use App\Exceptions\OrderValidationException;
use App\Exceptions\PhoneRequiredException;
use App\Services\BranchHoursService;
use App\Services\OrderService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

/**
 * The customer app/website's own view of ordering — same OrderService
 * as the staff-facing OrderController (Phase 3), just called with the
 * authenticated Customer as the ordering party instead of a staff
 * `placedBy` user, and every query scoped to that customer's own orders
 * only (never another customer's, even within the same restaurant).
 */
class CustomerOrderController extends Controller
{
    public function __construct(private OrderService $orders, private BranchHoursService $hours)
    {
    }

    public function index(Request $request)
    {
        $orders = Order::where('customer_id', $request->user()->id)
            ->with('table', 'deliveryZone')
            ->latest()
            ->get();

        return ApiResponse::success($orders);
    }

    public function show(Request $request, int $order)
    {
        $order = Order::where('customer_id', $request->user()->id)->findOrFail($order);

        return ApiResponse::success($order->load('items.modifiers', 'table', 'deliveryZone', 'coupon', 'payments'));
    }

    /**
     * `customer_address_id` is a convenience over typing the address out
     * again: when given, it's resolved (and ownership-checked) here and
     * its stored full_address/latitude/longitude replace whatever was
     * submitted in delivery_address/delivery_latitude/delivery_longitude,
     * so a saved address always wins over stale/mismatched free-text
     * fields in the same request.
     */
    public function store(Request $request)
    {
        $customer = $request->user();
        $restaurant = $customer->restaurant;

        // Signed in with Google / Apple and no number yet: the restaurant has to
        // be able to reach them about the order.
        if (blank($customer->phone)) {
            throw new PhoneRequiredException();
        }

        $data = $request->validate([
            'branch_id' => ['required', 'integer'],
            'order_type' => ['required', 'in:DINE_IN,TAKEAWAY,DELIVERY'],
            'table_id' => ['nullable', 'integer'],
            'customer_address_id' => ['nullable', 'integer'],
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

        if (! empty($data['customer_address_id'])) {
            $address = $customer->addresses()->findOrFail($data['customer_address_id']);
            $data['delivery_address'] = $address->full_address;
            $data['delivery_latitude'] = $address->latitude;
            $data['delivery_longitude'] = $address->longitude;
        }

        $branch = Branch::findOrFail($data['branch_id']);

        // A customer can only order while the branch takes that kind of order.
        // (Staff keep the till open — they place orders through OrderController.)
        $timezone = $restaurant->timezone ?: 'UTC';
        $status = $this->hours->status($branch, $data['order_type'], $timezone);
        if (! $status['is_open']) {
            $label = ['DINE_IN' => 'Dine-in', 'TAKEAWAY' => 'Takeaway', 'DELIVERY' => 'Delivery'][$data['order_type']];
            $opens = $status['opens_at']
                ? ' It opens '.\Carbon\CarbonImmutable::parse($status['opens_at'])->format('D g:i A').'.'
                : '';

            throw new OrderValidationException("{$label} is closed at {$branch->name} right now.{$opens}");
        }

        $order = $this->orders->createOrder($restaurant, $branch, null, $data, $customer);

        AuditLog::create([
            'restaurant_id' => $restaurant->id,
            'branch_id' => $branch->id,
            'user_id' => null,
            'action' => 'order.created_by_customer',
            'subject_type' => Order::class,
            'subject_id' => $order->id,
            'changes' => ['new' => [
                'order_number' => $order->order_number, 'total_amount' => (string) $order->total_amount,
                'customer_id' => $customer->id,
            ]],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return ApiResponse::success($order->load('items.modifiers', 'table', 'deliveryZone', 'coupon'), 201);
    }
}
