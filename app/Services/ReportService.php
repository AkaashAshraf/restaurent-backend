<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Restaurant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Every method here goes through `DB::table(...)` (the query builder),
 * never an Eloquent model, and aggregates in SQL rather than pulling
 * rows into PHP. Two deliberate reasons: a report has no use for a
 * hydrated model, only numbers; and going through Eloquent would apply
 * `Order`/`Payment`'s own enum casts to a `selectRaw()` alias column
 * (e.g. `status` on a grouped row), turning it into an enum instance
 * that can't be used as a PHP array key — `DB::table()` sidesteps that
 * entirely by never hydrating anything.
 *
 * None of these tables use `BelongsToTenant`'s automatic scoping this
 * way (a plain query builder call doesn't see Eloquent global scopes at
 * all), so every method filters by `restaurant_id` explicitly instead —
 * same "don't lean on the global scope for something this size" instinct
 * Phase 7's `CouponService` already documented.
 */
class ReportService
{
    /**
     * A restaurant-wide user gets `$branchIds = null` (no branch filter
     * at all); a branch-scoped user gets their own accessible branch ids;
     * an explicit `$branchId` (validated by the controller against the
     * caller's own access first) narrows either case down to just one.
     */
    public function salesSummary(Restaurant $restaurant, Carbon $from, Carbon $to, ?array $branchIds, ?int $branchId): array
    {
        $byStatus = $this->scopedOrders($restaurant, $from, $to, $branchIds, $branchId)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        // Revenue/averages deliberately exclude CANCELLED orders — a
        // cancelled order generated no real revenue, even though it still
        // counts in `orders_by_status`/`order_count` for a complete
        // picture of what came through.
        $totals = $this->scopedOrders($restaurant, $from, $to, $branchIds, $branchId)
            ->where('status', '!=', OrderStatus::CANCELLED->value)
            ->selectRaw('
                COUNT(*) as order_count,
                COALESCE(SUM(subtotal), 0) as subtotal,
                COALESCE(SUM(tax_amount), 0) as tax_amount,
                COALESCE(SUM(delivery_fee), 0) as delivery_fee,
                COALESCE(SUM(discount_amount), 0) as discount_amount,
                COALESCE(SUM(total_amount), 0) as total_amount
            ')
            ->first();

        $byOrderType = $this->scopedOrders($restaurant, $from, $to, $branchIds, $branchId)
            ->where('status', '!=', OrderStatus::CANCELLED->value)
            ->selectRaw('order_type, COUNT(*) as count, COALESCE(SUM(total_amount), 0) as revenue')
            ->groupBy('order_type')
            ->get()
            ->keyBy('order_type')
            ->map(fn ($row) => ['count' => (int) $row->count, 'revenue' => round((float) $row->revenue, 2)]);

        // One point per day, for the dashboard's revenue chart (cancelled
        // orders excluded, same as the revenue figures above).
        $byDay = $this->scopedOrders($restaurant, $from, $to, $branchIds, $branchId)
            ->where('status', '!=', OrderStatus::CANCELLED->value)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as count, COALESCE(SUM(total_amount), 0) as revenue')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(fn ($row) => [
                'date' => (string) $row->day,
                'orders' => (int) $row->count,
                'revenue' => round((float) $row->revenue, 2),
            ])
            ->values();

        $orderCount = (int) $totals->order_count;
        $netRevenue = round((float) $totals->total_amount, 2);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'total_orders' => (int) $byStatus->sum(),
            'orders_by_status' => $byStatus->map(fn ($count) => (int) $count),
            'order_count' => $orderCount,
            'subtotal' => round((float) $totals->subtotal, 2),
            'tax_amount' => round((float) $totals->tax_amount, 2),
            'delivery_fee' => round((float) $totals->delivery_fee, 2),
            'discount_amount' => round((float) $totals->discount_amount, 2),
            'net_revenue' => $netRevenue,
            'average_order_value' => $orderCount > 0 ? round($netRevenue / $orderCount, 2) : 0.0,
            'by_order_type' => $byOrderType,
            'by_day' => $byDay,
        ];
    }

    public function paymentsSummary(Restaurant $restaurant, Carbon $from, Carbon $to, ?array $branchIds, ?int $branchId): array
    {
        $query = DB::table('payments')
            ->where('restaurant_id', $restaurant->id)
            ->whereBetween('created_at', [$from, $to]);

        $this->applyBranchScope($query, $branchIds, $branchId);

        $rows = $query->selectRaw('method, status, COUNT(*) as count, COALESCE(SUM(amount), 0) as total')
            ->groupBy('method', 'status')
            ->get();

        $byMethod = [];
        foreach ($rows as $row) {
            $byMethod[$row->method][$row->status] = ['count' => (int) $row->count, 'total' => round((float) $row->total, 2)];
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'by_method' => $byMethod,
            'total_collected' => round((float) $rows->where('status', PaymentStatus::PAID->value)->sum('total'), 2),
            'total_pending' => round((float) $rows->where('status', PaymentStatus::PENDING->value)->sum('total'), 2),
            'total_refunded' => round((float) $rows->where('status', PaymentStatus::REFUNDED->value)->sum('total'), 2),
        ];
    }

    public function topProducts(Restaurant $restaurant, Carbon $from, Carbon $to, ?array $branchIds, ?int $branchId, int $limit = 10): array
    {
        $query = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.restaurant_id', $restaurant->id)
            ->whereBetween('orders.created_at', [$from, $to])
            ->where('orders.status', '!=', OrderStatus::CANCELLED->value);

        $this->applyBranchScope($query, $branchIds, $branchId, 'orders.branch_id');

        return $query->selectRaw('
                order_items.product_id,
                order_items.product_name,
                SUM(order_items.quantity) as quantity_sold,
                COALESCE(SUM(order_items.line_total), 0) as revenue
            ')
            // Grouped by name (the durable snapshot at order time — see
            // order_items' own migration comment), not just product_id,
            // since product_id is nullable once a product is deleted.
            ->groupBy('order_items.product_name', 'order_items.product_id')
            ->orderByDesc('quantity_sold')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'product_id' => $row->product_id,
                'product_name' => $row->product_name,
                'quantity_sold' => (int) $row->quantity_sold,
                'revenue' => round((float) $row->revenue, 2),
            ])
            ->all();
    }

    /**
     * Always restaurant-wide, never branch-filtered — a `Coupon` has no
     * branch dimension at all (Phase 7's own design: a promo code is a
     * marketing decision made once for the whole restaurant), so there's
     * nothing here for a branch_id to narrow.
     */
    public function couponUsage(Restaurant $restaurant, Carbon $from, Carbon $to): array
    {
        return DB::table('coupon_redemptions')
            ->join('coupons', 'coupons.id', '=', 'coupon_redemptions.coupon_id')
            ->where('coupon_redemptions.restaurant_id', $restaurant->id)
            ->whereBetween('coupon_redemptions.created_at', [$from, $to])
            ->selectRaw('
                coupons.id as coupon_id,
                coupons.code,
                coupons.type,
                COUNT(*) as redemptions_count,
                COALESCE(SUM(coupon_redemptions.discount_amount), 0) as total_discount
            ')
            ->groupBy('coupons.id', 'coupons.code', 'coupons.type')
            ->orderByDesc('redemptions_count')
            ->get()
            ->map(fn ($row) => [
                'coupon_id' => $row->coupon_id,
                'code' => $row->code,
                'type' => $row->type,
                'redemptions_count' => (int) $row->redemptions_count,
                'total_discount' => round((float) $row->total_discount, 2),
            ])
            ->all();
    }

    private function scopedOrders(Restaurant $restaurant, Carbon $from, Carbon $to, ?array $branchIds, ?int $branchId)
    {
        $query = DB::table('orders')
            ->where('restaurant_id', $restaurant->id)
            ->whereBetween('created_at', [$from, $to]);

        $this->applyBranchScope($query, $branchIds, $branchId);

        return $query;
    }

    private function applyBranchScope($query, ?array $branchIds, ?int $branchId, string $column = 'branch_id'): void
    {
        if ($branchId !== null) {
            $query->where($column, $branchId);
        } elseif ($branchIds !== null) {
            $query->whereIn($column, $branchIds ?: [0]);
        }
    }
}
