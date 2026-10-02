<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Restaurant;
use App\Models\Subscription;
use App\Support\ApiResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        return ApiResponse::success($this->overview() + [
            'total_restaurants' => Restaurant::count(),
            'active_restaurants' => Restaurant::where('status', 'ACTIVE')->count(),
            'inactive_restaurants' => Restaurant::whereIn('status', ['INACTIVE', 'SUSPENDED'])->count(),
            'expired_restaurants' => Restaurant::where('status', 'EXPIRED')->count(),
            'total_branches' => Branch::withoutTenantScope()->count(),
            'active_subscriptions' => Subscription::where('status', 'ACTIVE')
                ->where(fn ($q) => $q->whereNull('expiry_date')->orWhere('expiry_date', '>=', Carbon::today()))
                ->count(),
            'expired_subscriptions' => Subscription::where('status', 'EXPIRED')
                ->orWhere(fn ($q) => $q->where('status', 'ACTIVE')->where('expiry_date', '<', Carbon::today()))
                ->count(),
        ]);
    }

    /**
     * Everything the dashboard shows beyond the original counts. Orders are read
     * straight from the table (the super admin has no tenant), cancelled orders never
     * count as sales, and money is kept per currency because restaurants differ.
     */
    private function overview(): array
    {
        $cancelled = 'CANCELLED';
        $now = now();
        $from30 = $now->copy()->subDays(29)->startOfDay();
        $from7 = $now->copy()->subDays(6)->startOfDay();
        $today = $now->copy()->startOfDay();

        $live = fn () => DB::table('orders')->join('restaurants', 'restaurants.id', '=', 'orders.restaurant_id')
            ->whereNull('restaurants.deleted_at')->where('orders.status', '!=', $cancelled);

        // Sales per currency: today, 7 days, 30 days.
        $sales = $live()->where('orders.created_at', '>=', $from30)
            ->selectRaw('restaurants.currency as currency, COALESCE(SUM(orders.total_amount),0) as last_30_days, COUNT(*) as orders_30_days,
                COALESCE(SUM(CASE WHEN orders.created_at >= ? THEN orders.total_amount ELSE 0 END),0) as last_7_days,
                COALESCE(SUM(CASE WHEN orders.created_at >= ? THEN orders.total_amount ELSE 0 END),0) as today', [$from7, $today])
            ->groupBy('restaurants.currency')->orderByDesc('last_30_days')->get()
            ->map(fn ($r) => [
                'currency' => $r->currency, 'today' => round((float) $r->today, 2), 'last_7_days' => round((float) $r->last_7_days, 2),
                'last_30_days' => round((float) $r->last_30_days, 2), 'orders_30_days' => (int) $r->orders_30_days,
            ])->values();

        // Daily trend, for the currency that sells the most (adding PKR to USD would mean nothing).
        $primary = $sales->first()['currency'] ?? null;
        $byDay = $primary === null ? collect() : $live()->where('restaurants.currency', $primary)->where('orders.created_at', '>=', $from30)
            ->selectRaw('DATE(orders.created_at) as day, COUNT(*) as orders, COALESCE(SUM(orders.total_amount),0) as revenue')
            ->groupBy('day')->get()->keyBy('day');
        $trend = [];
        for ($d = $from30->copy(); $d <= $now; $d->addDay()) {
            $row = $byDay->get($d->toDateString());
            $trend[] = ['date' => $d->toDateString(), 'revenue' => round((float) ($row->revenue ?? 0), 2), 'orders' => (int) ($row->orders ?? 0)];
        }

        $totals = [
            'staff' => DB::table('users')->join('restaurants', 'restaurants.id', '=', 'users.restaurant_id')->whereNull('restaurants.deleted_at')
                ->whereNull('users.deleted_at')->where('users.is_super_admin', false)->count(),
            'customers' => DB::table('customers')->join('restaurants', 'restaurants.id', '=', 'customers.restaurant_id')->whereNull('restaurants.deleted_at')
                ->whereNull('customers.deleted_at')->count(),
            'orders' => DB::table('orders')->join('restaurants', 'restaurants.id', '=', 'orders.restaurant_id')->whereNull('restaurants.deleted_at')->count(),
            'orders_today' => DB::table('orders')->join('restaurants', 'restaurants.id', '=', 'orders.restaurant_id')->whereNull('restaurants.deleted_at')
                ->where('orders.created_at', '>=', $today)->count(),
            'products' => DB::table('products')->join('restaurants', 'restaurants.id', '=', 'products.restaurant_id')->whereNull('restaurants.deleted_at')
                ->whereNull('products.deleted_at')->count(),
        ];

        // Busiest restaurants (by orders in the last 30 days), each with the numbers the tabs open onto.
        $busy = $live()->where('orders.created_at', '>=', $from30)
            ->selectRaw('orders.restaurant_id as id, COUNT(*) as orders, COALESCE(SUM(orders.total_amount),0) as sales')
            ->groupBy('orders.restaurant_id')->orderByDesc('orders')->limit(8)->get()->keyBy('id');
        $top = DB::table('restaurants')->whereNull('deleted_at')->whereIn('id', $busy->keys())
            ->select('id', 'name', 'currency', 'status')
            ->selectRaw('(select count(*) from users where users.restaurant_id = restaurants.id and users.deleted_at is null and users.is_super_admin = 0) as staff')
            ->selectRaw('(select count(*) from customers where customers.restaurant_id = restaurants.id and customers.deleted_at is null) as customers')
            ->selectRaw('(select count(*) from products where products.restaurant_id = restaurants.id and products.deleted_at is null) as products')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id, 'name' => $r->name, 'currency' => $r->currency, 'status' => $r->status,
                'staff' => (int) $r->staff, 'customers' => (int) $r->customers, 'products' => (int) $r->products,
                'orders_30_days' => (int) $busy[$r->id]->orders, 'sales_30_days' => round((float) $busy[$r->id]->sales, 2),
            ])->sortByDesc('orders_30_days')->values();

        // Restaurants by status, and active subscriptions per plan.
        $byStatus = DB::table('restaurants')->whereNull('deleted_at')->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n);
        $byPlan = DB::table('subscriptions')->join('subscription_plans', 'subscription_plans.id', '=', 'subscriptions.subscription_plan_id')
            ->join('restaurants', 'restaurants.id', '=', 'subscriptions.restaurant_id')->whereNull('restaurants.deleted_at')
            ->where('subscriptions.status', 'ACTIVE')
            ->where(fn ($q) => $q->whereNull('subscriptions.expiry_date')->orWhere('subscriptions.expiry_date', '>=', Carbon::today()))
            ->selectRaw('subscription_plans.id as plan_id, subscription_plans.name as plan, COUNT(*) as restaurants')->groupBy('subscription_plans.id', 'subscription_plans.name')
            ->orderByDesc('restaurants')->get()->map(fn ($r) => ['plan_id' => (int) $r->plan_id, 'plan' => $r->plan, 'restaurants' => (int) $r->restaurants]);

        // Subscriptions about to run out (next 14 days).
        $expiring = DB::table('subscriptions')->join('restaurants', 'restaurants.id', '=', 'subscriptions.restaurant_id')
            ->join('subscription_plans', 'subscription_plans.id', '=', 'subscriptions.subscription_plan_id')
            ->whereNull('restaurants.deleted_at')->where('subscriptions.status', 'ACTIVE')
            ->whereBetween('subscriptions.expiry_date', [Carbon::today(), Carbon::today()->addDays(14)])
            ->orderBy('subscriptions.expiry_date')->limit(6)
            ->get(['restaurants.id', 'restaurants.name', 'subscription_plans.name as plan', 'subscriptions.expiry_date'])
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'plan' => $r->plan, 'expiry_date' => (string) $r->expiry_date,
                'days_left' => (int) Carbon::today()->diffInDays(Carbon::parse($r->expiry_date), false)]);

        // Restaurants that need a look: not active, or with no live subscription.
        $attention = DB::table('restaurants')->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('status', '!=', 'ACTIVE')->orWhereNotExists(fn ($s) => $s->selectRaw('1')->from('subscriptions')
                ->whereColumn('subscriptions.restaurant_id', 'restaurants.id')->where('subscriptions.status', 'ACTIVE')
                ->where(fn ($e) => $e->whereNull('subscriptions.expiry_date')->orWhere('subscriptions.expiry_date', '>=', Carbon::today()))))
            ->orderByDesc('created_at')->limit(6)->get(['id', 'name', 'status'])
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'status' => $r->status,
                'reason' => $r->status !== 'ACTIVE' ? 'Restaurant is '.strtolower($r->status) : 'No active subscription']);

        $recent = DB::table('restaurants')->whereNull('deleted_at')->orderByDesc('created_at')->limit(5)->get(['id', 'name', 'status', 'created_at'])
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'status' => $r->status, 'created_at' => $r->created_at]);

        return [
            'platform' => $totals,
            'sales' => $sales,
            'trend' => ['currency' => $primary, 'points' => $trend],
            'top_restaurants' => $top,
            'restaurants_by_status' => $byStatus,
            'subscriptions_by_plan' => $byPlan,
            'expiring_soon' => $expiring,
            'needs_attention' => $attention,
            'recent_restaurants' => $recent,
        ];
    }
}
