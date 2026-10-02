<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Support\ApiResponse;
use App\Support\CustomerAppBranding;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What the platform's super admin can see inside one restaurant: its staff,
 * customers, orders, sales and products (the Restaurant detail tabs).
 *
 * Read-only, and all of it goes through the query builder with an explicit
 * `restaurant_id` — the super admin has no tenant context of their own, so
 * nothing here may lean on the tenant global scope. Revenue figures leave out
 * CANCELLED orders, the same as the restaurant's own reports.
 */
class RestaurantInsightsController extends Controller
{
    private const ORDER_TYPES = 'DINE_IN,TAKEAWAY,DELIVERY';

    // ------------------------------------------------------------------ overview

    /** GET restaurants/{restaurant}/insights — the numbers on top of each tab. */
    public function overview(Restaurant $restaurant)
    {
        $id = $restaurant->id;
        $cancelled = OrderStatus::CANCELLED->value;

        $staff = DB::table('users')->where('restaurant_id', $id)->whereNull('deleted_at')->where('is_super_admin', false)
            ->selectRaw("COUNT(*) as total, SUM(status = 'ACTIVE') as active, SUM(status != 'ACTIVE') as inactive")->first();

        $customers = DB::table('customers')->where('restaurant_id', $id)->whereNull('deleted_at')
            ->selectRaw("COUNT(*) as total, SUM(status = 'ACTIVE') as active, SUM(created_at >= ?) as new_30d", [now()->subDays(30)])->first();

        $orders = DB::table('orders')->where('restaurant_id', $id)
            ->selectRaw("COUNT(*) as total, SUM(created_at >= ?) as today, SUM(status NOT IN ('COMPLETED','CANCELLED')) as open_now", [now()->startOfDay()])->first();

        $money = fn (Carbon $since) => round((float) DB::table('orders')->where('restaurant_id', $id)->where('status', '!=', $cancelled)
            ->where('created_at', '>=', $since)->sum('total_amount'), 2);

        $products = DB::table('products')->where('restaurant_id', $id)->whereNull('deleted_at')
            ->selectRaw("COUNT(*) as total, SUM(status = 'ACTIVE') as active, SUM(status != 'ACTIVE') as inactive")->first();

        return ApiResponse::success([
            'branches' => DB::table('branches')->where('restaurant_id', $id)->whereNull('deleted_at')->count(),
            'staff' => ['total' => (int) $staff->total, 'active' => (int) $staff->active, 'inactive' => (int) $staff->inactive],
            'customers' => ['total' => (int) $customers->total, 'active' => (int) $customers->active, 'new_30d' => (int) $customers->new_30d],
            'orders' => ['total' => (int) $orders->total, 'today' => (int) $orders->today, 'open_now' => (int) $orders->open_now],
            'sales' => [
                'today' => $money(now()->startOfDay()),
                'last_7_days' => $money(now()->subDays(6)->startOfDay()),
                'last_30_days' => $money(now()->subDays(29)->startOfDay()),
                'all_time' => round((float) DB::table('orders')->where('restaurant_id', $id)->where('status', '!=', $cancelled)->sum('total_amount'), 2),
            ],
            'products' => [
                'total' => (int) $products->total,
                'active' => (int) $products->active,
                'inactive' => (int) $products->inactive,
                'categories' => DB::table('categories')->where('restaurant_id', $id)->whereNull('deleted_at')->count(),
            ],
        ]);
    }

    // --------------------------------------------------------------------- staff

    /** GET restaurants/{restaurant}/staff?status=&role=&branch_id=&search=&page= */
    public function staff(Request $request, Restaurant $restaurant)
    {
        $f = $request->validate([
            'status' => ['nullable', 'in:ACTIVE,INACTIVE'],
            'role' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $base = fn () => DB::table('users')->where('users.restaurant_id', $restaurant->id)
            ->whereNull('users.deleted_at')->where('users.is_super_admin', false);

        $query = $base();
        if (! empty($f['status'])) {
            $query->where('users.status', $f['status']);
        }
        if (! empty($f['role'])) {
            $query->whereExists(fn ($q) => $q->selectRaw('1')->from('user_roles')->whereColumn('user_roles.user_id', 'users.id')->where('user_roles.role_id', $f['role']));
        }
        if (! empty($f['branch_id'])) {
            $query->whereExists(fn ($q) => $q->selectRaw('1')->from('user_branches')->whereColumn('user_branches.user_id', 'users.id')->where('user_branches.branch_id', $f['branch_id']));
        }
        if (! empty($f['search'])) {
            $like = '%'.$f['search'].'%';
            $query->where(fn ($q) => $q->where('users.name', 'like', $like)->orWhere('users.email', 'like', $like)->orWhere('users.phone', 'like', $like));
        }

        $page = $query->select('users.id', 'users.name', 'users.email', 'users.phone', 'users.status', 'users.last_login_at', 'users.created_at')
            ->orderByRaw("users.status = 'ACTIVE' desc")->orderBy('users.name')
            ->paginate($f['per_page'] ?? 25);

        $ids = collect($page->items())->pluck('id');
        $roles = DB::table('user_roles')->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->whereIn('user_roles.user_id', $ids)->select('user_roles.user_id', 'roles.id', 'roles.name')->get()->groupBy('user_id');
        $branches = DB::table('user_branches')->join('branches', 'branches.id', '=', 'user_branches.branch_id')
            ->whereIn('user_branches.user_id', $ids)->select('user_branches.user_id', 'branches.id', 'branches.name')->get()->groupBy('user_id');

        $page->through(function ($u) use ($roles, $branches) {
            $u->roles = ($roles[$u->id] ?? collect())->map(fn ($r) => ['id' => $r->id, 'name' => $r->name])->values();
            $u->branches = ($branches[$u->id] ?? collect())->map(fn ($b) => ['id' => $b->id, 'name' => $b->name])->values();

            return $u;
        });

        // Counts by role (ignores the list filters, so the cards always show the whole team).
        $rows = DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->join('users', 'users.id', '=', 'user_roles.user_id')
            ->where('users.restaurant_id', $restaurant->id)->whereNull('users.deleted_at')->where('users.is_super_admin', false)
            ->selectRaw("roles.id as role_id, roles.name as role, SUM(users.status = 'ACTIVE') as active, SUM(users.status != 'ACTIVE') as inactive, COUNT(*) as total")
            ->groupBy('roles.id', 'roles.name')->orderByDesc('total')->get()
            ->map(fn ($r) => ['role_id' => (int) $r->role_id, 'role' => $r->role, 'active' => (int) $r->active, 'inactive' => (int) $r->inactive, 'total' => (int) $r->total]);

        $totals = $base()->selectRaw("COUNT(*) as total, SUM(status = 'ACTIVE') as active, SUM(status != 'ACTIVE') as inactive")->first();

        return ApiResponse::success([
            'summary' => ['total' => (int) $totals->total, 'active' => (int) $totals->active, 'inactive' => (int) $totals->inactive, 'by_role' => $rows],
            'staff' => $page,
        ]);
    }

    // ----------------------------------------------------------------- customers

    /** GET restaurants/{restaurant}/customers?status=&search=&sort=newest|most_orders|top_spenders */
    public function customers(Request $request, Restaurant $restaurant)
    {
        $f = $request->validate([
            'status' => ['nullable', 'in:ACTIVE,INACTIVE'],
            'search' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', 'in:newest,most_orders,top_spenders'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $cancelled = OrderStatus::CANCELLED->value;

        $query = DB::table('customers')->where('customers.restaurant_id', $restaurant->id)->whereNull('customers.deleted_at');
        if (! empty($f['status'])) {
            $query->where('customers.status', $f['status']);
        }
        if (! empty($f['search'])) {
            $like = '%'.$f['search'].'%';
            $query->where(fn ($q) => $q->where('customers.name', 'like', $like)->orWhere('customers.phone', 'like', $like)->orWhere('customers.email', 'like', $like));
        }

        $orders = fn (string $select) => "(select {$select} from orders where orders.customer_id = customers.id and orders.status != '{$cancelled}')";
        $query->select('customers.id', 'customers.name', 'customers.phone', 'customers.email', 'customers.status', 'customers.created_at')
            ->selectRaw('(customers.google_id is not null) as via_google, (customers.apple_id is not null) as via_apple, (customers.password is not null) as has_password')
            ->selectRaw($orders('count(*)').' as orders_count')
            ->selectRaw($orders('coalesce(sum(total_amount), 0)').' as total_spent')
            ->selectRaw($orders('max(created_at)').' as last_order_at');

        match ($f['sort'] ?? 'newest') {
            'most_orders' => $query->orderByDesc('orders_count'),
            'top_spenders' => $query->orderByDesc('total_spent'),
            default => $query->orderByDesc('customers.created_at'),
        };

        $page = $query->paginate($f['per_page'] ?? 25)->through(function ($c) {
            $c->orders_count = (int) $c->orders_count;
            $c->total_spent = round((float) $c->total_spent, 2);
            $c->signed_in_with = array_values(array_filter([
                $c->via_google ? 'google' : null, $c->via_apple ? 'apple' : null, $c->has_password ? 'password' : null,
            ]));
            unset($c->via_google, $c->via_apple, $c->has_password);

            return $c;
        });

        $s = DB::table('customers')->where('restaurant_id', $restaurant->id)->whereNull('deleted_at')
            ->selectRaw("COUNT(*) as total, SUM(status = 'ACTIVE') as active, SUM(status != 'ACTIVE') as inactive, SUM(created_at >= ?) as new_30d, SUM(google_id is not null) as google, SUM(apple_id is not null) as apple", [now()->subDays(30)])->first();
        $ordering = DB::table('orders')->where('restaurant_id', $restaurant->id)->whereNotNull('customer_id')->distinct()->count('customer_id');

        return ApiResponse::success([
            'summary' => [
                'total' => (int) $s->total, 'active' => (int) $s->active, 'inactive' => (int) $s->inactive,
                'new_30d' => (int) $s->new_30d, 'with_orders' => $ordering, 'google' => (int) $s->google, 'apple' => (int) $s->apple,
            ],
            'customers' => $page,
        ]);
    }

    // -------------------------------------------------------------------- orders

    /** GET restaurants/{restaurant}/orders?status=&order_type=&branch_id=&from=&to=&payment=&search= */
    public function orders(Request $request, Restaurant $restaurant)
    {
        $f = $this->orderFilters($request);

        $rows = $this->ordersQuery($restaurant, $f)
            ->leftJoin('branches', 'branches.id', '=', 'orders.branch_id')
            ->leftJoin('customers', 'customers.id', '=', 'orders.customer_id')
            ->select('orders.id', 'orders.order_number', 'orders.order_type', 'orders.status', 'orders.total_amount', 'orders.created_at', 'orders.customer_id',
                'branches.name as branch_name', 'customers.name as customer_name', 'customers.phone as customer_phone')
            ->selectRaw("(select count(*) from payments where payments.order_id = orders.id and payments.status = 'PAID') > 0 as paid")
            ->orderByDesc('orders.created_at')
            ->paginate($f['per_page'] ?? 25)
            ->through(function ($o) {
                $o->total_amount = round((float) $o->total_amount, 2);
                $o->paid = (bool) $o->paid;

                return $o;
            });

        // Counts for the status chips: every filter except the status itself.
        $others = $f;
        unset($others['status']);
        $byStatus = $this->ordersQuery($restaurant, $others)->selectRaw('orders.status, COUNT(*) as count')->groupBy('orders.status')->pluck('count', 'status')->map(fn ($n) => (int) $n);
        $byType = $this->ordersQuery($restaurant, $f)->selectRaw('orders.order_type, COUNT(*) as count')->groupBy('orders.order_type')->pluck('count', 'order_type')->map(fn ($n) => (int) $n);
        $revenue = $this->ordersQuery($restaurant, $f)->where('orders.status', '!=', OrderStatus::CANCELLED->value)->sum('orders.total_amount');

        return ApiResponse::success([
            'summary' => [
                'total' => $rows->total(),
                'by_status' => $byStatus,
                'all_statuses' => (int) $byStatus->sum(),
                'by_type' => $byType,
                'revenue' => round((float) $revenue, 2),
            ],
            'orders' => $rows,
        ]);
    }

    // --------------------------------------------------------------------- sales

    /** GET restaurants/{restaurant}/sales?from=&to=&branch_id=&order_type=&payment_method= (default: last 30 days) */
    public function sales(Request $request, Restaurant $restaurant)
    {
        $f = $this->orderFilters($request);
        $to = isset($f['to']) ? Carbon::parse($f['to'])->endOfDay() : now()->endOfDay();
        $from = isset($f['from']) ? Carbon::parse($f['from'])->startOfDay() : $to->copy()->subDays(29)->startOfDay();
        $f['from'] = $from->toDateString();
        $f['to'] = $to->toDateString();
        unset($f['status'], $f['search'], $f['payment']);

        $live = fn () => $this->ordersQuery($restaurant, $f)->where('orders.status', '!=', OrderStatus::CANCELLED->value);

        $t = $live()->selectRaw('COUNT(*) as orders, COALESCE(SUM(subtotal),0) as subtotal, COALESCE(SUM(tax_amount),0) as tax, COALESCE(SUM(delivery_fee),0) as delivery_fee, COALESCE(SUM(discount_amount),0) as discount, COALESCE(SUM(total_amount),0) as total')->first();
        $cancelled = $this->ordersQuery($restaurant, $f)->where('orders.status', OrderStatus::CANCELLED->value)->count();

        $byDay = $live()->selectRaw('DATE(orders.created_at) as day, COUNT(*) as orders, COALESCE(SUM(orders.total_amount),0) as revenue')->groupBy('day')->orderBy('day')->get()
            ->map(fn ($r) => ['date' => (string) $r->day, 'orders' => (int) $r->orders, 'revenue' => round((float) $r->revenue, 2)]);
        $byType = $live()->selectRaw('orders.order_type as type, COUNT(*) as orders, COALESCE(SUM(orders.total_amount),0) as revenue')->groupBy('orders.order_type')->get()
            ->map(fn ($r) => ['type' => $r->type, 'orders' => (int) $r->orders, 'revenue' => round((float) $r->revenue, 2)]);
        $byBranch = $live()->leftJoin('branches', 'branches.id', '=', 'orders.branch_id')
            ->selectRaw('orders.branch_id, branches.name as branch, COUNT(*) as orders, COALESCE(SUM(orders.total_amount),0) as revenue')->groupBy('orders.branch_id', 'branches.name')->orderByDesc('revenue')->get()
            ->map(fn ($r) => ['branch_id' => $r->branch_id, 'branch' => $r->branch ?? 'Unknown', 'orders' => (int) $r->orders, 'revenue' => round((float) $r->revenue, 2)]);

        // How it was paid: money actually collected on those orders.
        $byMethod = DB::table('payments')->joinSub($live()->select('orders.id'), 'o', 'o.id', '=', 'payments.order_id')
            ->where('payments.status', 'PAID')->selectRaw('payments.method, COUNT(*) as payments, COALESCE(SUM(payments.amount),0) as total')->groupBy('payments.method')->get()
            ->map(fn ($r) => ['method' => $r->method, 'payments' => (int) $r->payments, 'total' => round((float) $r->total, 2)]);

        $top = DB::table('order_items')->joinSub($live()->select('orders.id'), 'o', 'o.id', '=', 'order_items.order_id')
            ->selectRaw('order_items.product_id, order_items.product_name, SUM(order_items.quantity) as units, COALESCE(SUM(order_items.line_total),0) as revenue')
            ->groupBy('order_items.product_id', 'order_items.product_name')->orderByDesc('revenue')->limit(10)->get()
            ->map(fn ($r) => ['product_id' => $r->product_id, 'name' => $r->product_name, 'units' => (int) $r->units, 'revenue' => round((float) $r->revenue, 2)]);

        $orders = (int) $t->orders;
        $total = round((float) $t->total, 2);

        return ApiResponse::success([
            'from' => $f['from'], 'to' => $f['to'],
            'orders' => $orders,
            'cancelled_orders' => $cancelled,
            'subtotal' => round((float) $t->subtotal, 2),
            'tax' => round((float) $t->tax, 2),
            'delivery_fee' => round((float) $t->delivery_fee, 2),
            'discount' => round((float) $t->discount, 2),
            'total_sales' => $total,
            'average_order_value' => $orders > 0 ? round($total / $orders, 2) : 0.0,
            'by_day' => $byDay,
            'by_type' => $byType,
            'by_branch' => $byBranch,
            'by_payment_method' => $byMethod,
            'top_products' => $top,
        ]);
    }

    // ------------------------------------------------------------------ products

    /** GET restaurants/{restaurant}/products?status=&category_id=&search=&sort=name|best_selling|price_high|price_low */
    public function products(Request $request, Restaurant $restaurant)
    {
        $f = $request->validate([
            'status' => ['nullable', 'in:ACTIVE,INACTIVE'],
            'category_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', 'in:name,best_selling,price_high,price_low'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $cancelled = OrderStatus::CANCELLED->value;

        $query = DB::table('products')->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->where('products.restaurant_id', $restaurant->id)->whereNull('products.deleted_at');
        if (! empty($f['status'])) {
            $query->where('products.status', $f['status']);
        }
        if (! empty($f['category_id'])) {
            $query->where('products.category_id', $f['category_id']);
        }
        if (! empty($f['search'])) {
            $query->where('products.name', 'like', '%'.$f['search'].'%');
        }

        $sold = fn (string $select) => "(select {$select} from order_items join orders on orders.id = order_items.order_id where order_items.product_id = products.id and orders.status != '{$cancelled}')";
        $query->select('products.id', 'products.name', 'products.image', 'products.base_price', 'products.status', 'products.category_id', 'categories.name as category')
            ->selectRaw('(select count(*) from product_modifier_groups where product_modifier_groups.product_id = products.id) as option_groups')
            ->selectRaw($sold('coalesce(sum(order_items.quantity), 0)').' as units_sold')
            ->selectRaw($sold('coalesce(sum(order_items.line_total), 0)').' as revenue');

        match ($f['sort'] ?? 'name') {
            'best_selling' => $query->orderByDesc('units_sold'),
            'price_high' => $query->orderByDesc('products.base_price'),
            'price_low' => $query->orderBy('products.base_price'),
            default => $query->orderBy('products.name'),
        };

        $page = $query->paginate($f['per_page'] ?? 25)->through(function ($p) {
            $p->image = CustomerAppBranding::url($p->image);
            $p->base_price = round((float) $p->base_price, 2);
            $p->units_sold = (int) $p->units_sold;
            $p->revenue = round((float) $p->revenue, 2);
            $p->option_groups = (int) $p->option_groups;

            return $p;
        });

        $s = DB::table('products')->where('restaurant_id', $restaurant->id)->whereNull('deleted_at')
            ->selectRaw("COUNT(*) as total, SUM(status = 'ACTIVE') as active, SUM(status != 'ACTIVE') as inactive")->first();
        $categories = DB::table('categories')->where('restaurant_id', $restaurant->id)->whereNull('deleted_at')
            ->select('id', 'name')->selectRaw('(select count(*) from products where products.category_id = categories.id and products.deleted_at is null) as products')
            ->orderBy('display_order')->orderBy('name')->get();

        return ApiResponse::success([
            'summary' => [
                'total' => (int) $s->total, 'active' => (int) $s->active, 'inactive' => (int) $s->inactive, 'categories' => $categories->count(),
            ],
            'categories' => $categories->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'products' => (int) $c->products]),
            'products' => $page,
        ]);
    }

    /** GET restaurants/{restaurant}/products/{product} — everything about one dish. */
    public function product(Restaurant $restaurant, int $product)
    {
        $p = DB::table('products')->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->where('products.restaurant_id', $restaurant->id)->where('products.id', $product)->whereNull('products.deleted_at')
            ->select('products.*', 'categories.name as category')->first();
        abort_if(! $p, 404, 'Product not found.');

        $groups = DB::table('product_modifier_groups')->join('modifier_groups', 'modifier_groups.id', '=', 'product_modifier_groups.modifier_group_id')
            ->where('product_modifier_groups.product_id', $p->id)->whereNull('modifier_groups.deleted_at')
            ->orderBy('product_modifier_groups.display_order')->select('modifier_groups.*')->get();
        $mods = DB::table('modifiers')->whereIn('modifier_group_id', $groups->pluck('id'))->whereNull('deleted_at')->orderBy('display_order')->get()->groupBy('modifier_group_id');

        $overrides = DB::table('branch_products')->where('product_id', $p->id)->get()->keyBy('branch_id');
        $branches = DB::table('branches')->where('restaurant_id', $restaurant->id)->whereNull('deleted_at')->orderBy('priority')->get()
            ->map(function ($b) use ($overrides, $p) {
                $o = $overrides->get($b->id);

                return [
                    'branch_id' => $b->id,
                    'branch' => $b->name,
                    'available' => $p->status === 'ACTIVE' && ($o ? (bool) $o->is_available : true),
                    'price' => round((float) ($o && $o->price_override !== null ? $o->price_override : $p->base_price), 2),
                    'price_overridden' => (bool) ($o && $o->price_override !== null),
                ];
            });

        $items = fn () => DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.product_id', $p->id)->where('orders.status', '!=', OrderStatus::CANCELLED->value);
        $t = $items()->selectRaw('COALESCE(SUM(order_items.quantity),0) as units, COALESCE(SUM(order_items.line_total),0) as revenue, COUNT(DISTINCT orders.id) as orders, MAX(orders.created_at) as last_sold_at')->first();
        $bySalesBranch = $items()->leftJoin('branches', 'branches.id', '=', 'orders.branch_id')
            ->selectRaw('branches.name as branch, SUM(order_items.quantity) as units, COALESCE(SUM(order_items.line_total),0) as revenue')->groupBy('branches.name')->orderByDesc('units')->get()
            ->map(fn ($r) => ['branch' => $r->branch ?? 'Unknown', 'units' => (int) $r->units, 'revenue' => round((float) $r->revenue, 2)]);

        return ApiResponse::success([
            'id' => $p->id,
            'name' => $p->name,
            'description' => $p->description,
            'image' => CustomerAppBranding::url($p->image),
            'category' => $p->category,
            'base_price' => round((float) $p->base_price, 2),
            'preparation_time_minutes' => $p->preparation_time_minutes,
            'status' => $p->status,
            'created_at' => $p->created_at,
            'option_groups' => $groups->map(fn ($g) => [
                'id' => $g->id, 'name' => $g->name, 'selection_type' => $g->selection_type, 'is_required' => (bool) $g->is_required,
                'min_selections' => (int) $g->min_selections, 'max_selections' => $g->max_selections,
                'options' => ($mods[$g->id] ?? collect())->map(fn ($m) => [
                    'id' => $m->id, 'name' => $m->name, 'price_adjustment' => round((float) $m->price_adjustment, 2), 'is_default' => (bool) $m->is_default, 'status' => $m->status,
                ])->values(),
            ])->values(),
            'branches' => $branches,
            'sales' => [
                'units_sold' => (int) $t->units, 'revenue' => round((float) $t->revenue, 2), 'orders' => (int) $t->orders,
                'last_sold_at' => $t->last_sold_at, 'by_branch' => $bySalesBranch,
            ],
        ]);
    }

    // ------------------------------------------------------------------- helpers

    private function orderFilters(Request $request): array
    {
        return $request->validate([
            'status' => ['nullable', 'in:'.implode(',', array_map(fn ($s) => $s->value, OrderStatus::cases()))],
            'order_type' => ['nullable', 'in:'.self::ORDER_TYPES],
            'branch_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'payment' => ['nullable', 'in:paid,unpaid'],
            'payment_method' => ['nullable', 'in:CASH,CARD,ONLINE'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
    }

    /** The restaurant's orders with every filter applied (no select, so callers choose what to aggregate). */
    private function ordersQuery(Restaurant $restaurant, array $f): Builder
    {
        $q = DB::table('orders')->where('orders.restaurant_id', $restaurant->id);

        if (! empty($f['from'])) {
            $q->where('orders.created_at', '>=', Carbon::parse($f['from'])->startOfDay());
        }
        if (! empty($f['to'])) {
            $q->where('orders.created_at', '<=', Carbon::parse($f['to'])->endOfDay());
        }
        foreach (['status', 'order_type', 'branch_id'] as $col) {
            if (! empty($f[$col])) {
                $q->where("orders.{$col}", $f[$col]);
            }
        }
        if (! empty($f['payment'])) {
            $paid = fn ($sub) => $sub->selectRaw('1')->from('payments')->whereColumn('payments.order_id', 'orders.id')->where('payments.status', 'PAID');
            $f['payment'] === 'paid' ? $q->whereExists($paid) : $q->whereNotExists($paid);
        }
        if (! empty($f['payment_method'])) {
            $q->whereExists(fn ($sub) => $sub->selectRaw('1')->from('payments')->whereColumn('payments.order_id', 'orders.id')
                ->where('payments.status', 'PAID')->where('payments.method', $f['payment_method']));
        }
        if (! empty($f['search'])) {
            $like = '%'.$f['search'].'%';
            $q->where(fn ($w) => $w->where('orders.order_number', 'like', $like)->orWhereIn('orders.customer_id',
                fn ($c) => $c->select('id')->from('customers')->where('restaurant_id', $restaurant->id)->where(fn ($x) => $x->where('name', 'like', $like)->orWhere('phone', 'like', $like))));
        }

        return $q;
    }
}
