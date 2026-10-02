<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The super admin's read-only look inside one restaurant. */
class RestaurantInsightsTest extends TestCase
{
    private function asSuperAdmin(): string
    {
        return $this->actingAsUser($this->makeSuperAdmin());
    }

    private function url(object $restaurant, string $path = 'insights'): string
    {
        return "/api/v1/super-admin/restaurants/{$restaurant->id}/{$path}";
    }

    private function makeProduct($restaurant, string $name, float $price = 10.0, string $status = 'ACTIVE', ?Category $category = null): Product
    {
        $category ??= Category::create(['restaurant_id' => $restaurant->id, 'name' => 'Menu '.uniqid(), 'slug' => 'menu-'.uniqid()]);

        return Product::create([
            'restaurant_id' => $restaurant->id, 'category_id' => $category->id, 'name' => $name,
            'slug' => Str::slug($name).'-'.uniqid(), 'base_price' => $price, 'status' => $status,
        ]);
    }

    private function makeCustomer($restaurant, string $name, string $phone, string $status = 'ACTIVE'): int
    {
        return DB::table('customers')->insertGetId([
            'restaurant_id' => $restaurant->id, 'name' => $name, 'phone' => $phone, 'status' => $status,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeOrder($restaurant, Branch $branch, array $o = []): int
    {
        $total = $o['total'] ?? 100;

        $id = DB::table('orders')->insertGetId([
            'restaurant_id' => $restaurant->id, 'branch_id' => $branch->id, 'customer_id' => $o['customer_id'] ?? null,
            'order_number' => 'ORD-'.uniqid(), 'order_type' => $o['type'] ?? 'TAKEAWAY', 'status' => $o['status'] ?? 'COMPLETED',
            'subtotal' => $total, 'tax_amount' => 0, 'delivery_fee' => 0, 'discount_amount' => 0, 'total_amount' => $total,
            'created_at' => $o['at'] ?? now(), 'updated_at' => now(),
        ]);

        if (isset($o['product'])) {
            DB::table('order_items')->insert([
                'restaurant_id' => $restaurant->id, 'order_id' => $id, 'product_id' => $o['product']->id,
                'product_name' => $o['product']->name, 'quantity' => $o['qty'] ?? 1, 'unit_price' => $total,
                'modifiers_total' => 0, 'line_total' => $total * ($o['qty'] ?? 1), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        if (isset($o['method'])) {
            DB::table('payments')->insert([
                'restaurant_id' => $restaurant->id, 'branch_id' => $branch->id, 'order_id' => $id, 'method' => $o['method'],
                'status' => $o['paid'] ?? true ? 'PAID' : 'PENDING', 'amount' => $total, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $id;
    }

    public function test_only_a_super_admin_can_open_these_endpoints(): void
    {
        [$restaurant, , $owner] = $this->makeRestaurantWithOwner('Locked Co');
        $token = $this->actingAsUser($owner);

        foreach (['insights', 'staff', 'customers', 'orders', 'sales', 'products'] as $path) {
            $this->withUserToken($token)->getJson($this->url($restaurant, $path))->assertStatus(403);
        }
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', '')->getJson($this->url($restaurant))->assertStatus(401);
    }

    public function test_staff_are_counted_by_role_and_status_without_leaking_other_restaurants(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Staffed Co');
        [$other, , ] = $this->makeRestaurantWithOwner('Other Co');

        $manager = $this->makeBranchScopedUser($restaurant, $branch, 'branch-manager');
        $manager2 = $this->makeBranchScopedUser($restaurant, $branch, 'branch-manager');
        $manager2->update(['status' => 'INACTIVE']);

        $data = $this->withUserToken($this->asSuperAdmin())->getJson($this->url($restaurant, 'staff'))
            ->assertOk()->json('data');

        $this->assertSame(3, $data['summary']['total']);
        $this->assertSame(2, $data['summary']['active']);
        $this->assertSame(1, $data['summary']['inactive']);
        $byRole = collect($data['summary']['by_role'])->keyBy('role');
        $this->assertSame(2, $byRole['Branch Manager']['total'] ?? $byRole->first()['total']);
        $this->assertSame(3, $data['staff']['total']);

        $names = collect($data['staff']['data'])->pluck('email');
        $this->assertTrue($names->contains($owner->email));
        $this->assertFalse($names->contains(User::where('restaurant_id', $other->id)->value('email')));

        // Filter: inactive only.
        $inactive = $this->withUserToken($this->asSuperAdmin())->getJson($this->url($restaurant, 'staff?status=INACTIVE'))
            ->assertOk()->json('data');
        $this->assertSame(1, $inactive['staff']['total']);
        $this->assertSame($manager2->id, $inactive['staff']['data'][0]['id']);
        // Counts at the top keep describing the whole team.
        $this->assertSame(3, $inactive['summary']['total']);
    }

    public function test_customers_show_order_totals_and_can_be_searched_and_sorted(): void
    {
        [$restaurant, $branch] = $this->makeRestaurantWithOwner('Fed Co');
        $ali = $this->makeCustomer($restaurant, 'Ali', '+920001');
        $sara = $this->makeCustomer($restaurant, 'Sara', '+920002', 'INACTIVE');
        $this->makeOrder($restaurant, $branch, ['customer_id' => $ali, 'total' => 50]);
        $this->makeOrder($restaurant, $branch, ['customer_id' => $ali, 'total' => 70]);
        $this->makeOrder($restaurant, $branch, ['customer_id' => $ali, 'total' => 900, 'status' => 'CANCELLED']);
        $this->makeOrder($restaurant, $branch, ['customer_id' => $sara, 'total' => 500]);

        $token = $this->asSuperAdmin();
        $data = $this->withUserToken($token)->getJson($this->url($restaurant, 'customers?sort=top_spenders'))
            ->assertOk()->json('data');

        $this->assertSame(2, $data['summary']['total']);
        $this->assertSame(1, $data['summary']['active']);
        $this->assertSame(1, $data['summary']['inactive']);
        $rows = $data['customers']['data'];
        $this->assertSame('Sara', $rows[0]['name']);          // 500 beats 120 (cancelled order not counted)
        $this->assertEquals(120, $rows[1]['total_spent']);
        $this->assertSame(2, $rows[1]['orders_count']);

        $found = $this->withUserToken($token)->getJson($this->url($restaurant, 'customers?search=0001'))->json('data');
        $this->assertSame(1, $found['customers']['total']);
        $this->assertSame('Ali', $found['customers']['data'][0]['name']);
    }

    public function test_orders_filter_by_status_type_dates_and_payment_with_status_counts(): void
    {
        [$restaurant, $branch] = $this->makeRestaurantWithOwner('Busy Co');
        [$other, $otherBranch] = $this->makeRestaurantWithOwner('Elsewhere Co');
        $this->makeOrder($restaurant, $branch, ['status' => 'COMPLETED', 'type' => 'DELIVERY', 'total' => 100, 'method' => 'CASH']);
        $this->makeOrder($restaurant, $branch, ['status' => 'COMPLETED', 'type' => 'TAKEAWAY', 'total' => 40, 'method' => 'CARD']);
        $this->makeOrder($restaurant, $branch, ['status' => 'PENDING', 'type' => 'DELIVERY', 'total' => 60]);
        $this->makeOrder($restaurant, $branch, ['status' => 'CANCELLED', 'type' => 'DELIVERY', 'total' => 999]);
        $this->makeOrder($restaurant, $branch, ['status' => 'COMPLETED', 'total' => 10, 'at' => now()->subYear()]);
        $this->makeOrder($other, $otherBranch, ['status' => 'COMPLETED', 'total' => 5000]);

        $token = $this->asSuperAdmin();
        $all = $this->withUserToken($token)->getJson($this->url($restaurant, 'orders'))->assertOk()->json('data');
        $this->assertSame(5, $all['summary']['total']);
        $this->assertSame(3, $all['summary']['by_status']['COMPLETED']);
        $this->assertSame(1, $all['summary']['by_status']['CANCELLED']);
        $this->assertSame(5, $all['orders']['total']);

        $delivery = $this->withUserToken($token)->getJson($this->url($restaurant, 'orders?order_type=DELIVERY'))->json('data');
        $this->assertSame(3, $delivery['orders']['total']);

        // Selecting a status narrows the list, but the chips keep counting every status.
        $pending = $this->withUserToken($token)->getJson($this->url($restaurant, 'orders?status=PENDING'))->json('data');
        $this->assertSame(1, $pending['orders']['total']);
        $this->assertSame(3, $pending['summary']['by_status']['COMPLETED']);

        $recent = $this->withUserToken($token)
            ->getJson($this->url($restaurant, 'orders?from='.now()->subDays(7)->toDateString().'&to='.now()->toDateString()))
            ->json('data');
        $this->assertSame(4, $recent['orders']['total']);

        $cash = $this->withUserToken($token)->getJson($this->url($restaurant, 'orders?payment_method=CASH'))->json('data');
        $this->assertSame(1, $cash['orders']['total']);
        $this->assertTrue($cash['orders']['data'][0]['paid']);

        $unpaid = $this->withUserToken($token)->getJson($this->url($restaurant, 'orders?payment=unpaid'))->json('data');
        $this->assertSame(3, $unpaid['orders']['total']);

        $this->withUserToken($token)->getJson($this->url($restaurant, 'orders?status=NOPE'))->assertStatus(422);
    }

    public function test_sales_totals_leave_out_cancelled_orders_and_break_down_by_day_type_and_method(): void
    {
        [$restaurant, $branch] = $this->makeRestaurantWithOwner('Selling Co');
        [$other, $otherBranch] = $this->makeRestaurantWithOwner('Rival Co');
        $pizza = $this->makeProduct($restaurant, 'Pizza', 100);
        $cola = $this->makeProduct($restaurant, 'Cola', 20);

        $this->makeOrder($restaurant, $branch, ['total' => 100, 'type' => 'DELIVERY', 'product' => $pizza, 'method' => 'CASH']);
        $this->makeOrder($restaurant, $branch, ['total' => 100, 'type' => 'TAKEAWAY', 'product' => $pizza, 'qty' => 2, 'method' => 'CARD']);
        $this->makeOrder($restaurant, $branch, ['total' => 20, 'type' => 'TAKEAWAY', 'product' => $cola, 'method' => 'CASH']);
        $this->makeOrder($restaurant, $branch, ['total' => 500, 'status' => 'CANCELLED', 'product' => $pizza]);
        $this->makeOrder($restaurant, $branch, ['total' => 70, 'at' => now()->subDays(60)]);
        $this->makeOrder($other, $otherBranch, ['total' => 9999]);

        $token = $this->asSuperAdmin();
        $d = $this->withUserToken($token)->getJson($this->url($restaurant, 'sales'))->assertOk()->json('data');

        // Default window is the last 30 days: the 60-day-old order is out.
        $this->assertSame(3, $d['orders']);
        $this->assertSame(1, $d['cancelled_orders']);
        $this->assertEquals(220, $d['total_sales']);
        $this->assertEqualsWithDelta(73.33, $d['average_order_value'], 0.01);
        $this->assertCount(1, $d['by_day']);
        $this->assertEquals(220, $d['by_day'][0]['revenue']);

        $types = collect($d['by_type'])->keyBy('type');
        $this->assertEquals(100, $types['DELIVERY']['revenue']);
        $this->assertEquals(120, $types['TAKEAWAY']['revenue']);

        $methods = collect($d['by_payment_method'])->keyBy('method');
        $this->assertEquals(120, $methods['CASH']['total']);
        $this->assertEquals(100, $methods['CARD']['total']);

        $this->assertSame('Pizza', $d['top_products'][0]['name']);
        $this->assertSame(3, $d['top_products'][0]['units']);   // cancelled order's pizza not counted

        $filtered = $this->withUserToken($token)->getJson($this->url($restaurant, 'sales?order_type=DELIVERY'))->json('data');
        $this->assertEquals(100, $filtered['total_sales']);

        $wide = $this->withUserToken($token)->getJson($this->url($restaurant, 'sales?from='.now()->subDays(90)->toDateString()))->json('data');
        $this->assertEquals(290, $wide['total_sales']);
    }

    public function test_products_list_counts_and_detail_with_options_branch_prices_and_sales(): void
    {
        [$restaurant, $branch] = $this->makeRestaurantWithOwner('Menu Co');
        [$other, ] = $this->makeRestaurantWithOwner('Other Menu Co');
        $burger = $this->makeProduct($restaurant, 'Burger', 12.5);
        $this->makeProduct($restaurant, 'Old Soup', 5, 'INACTIVE');
        $foreign = $this->makeProduct($other, 'Foreign Dish');

        DB::table('branch_products')->insert([
            'restaurant_id' => $restaurant->id, 'branch_id' => $branch->id, 'product_id' => $burger->id,
            'is_available' => true, 'price_override' => 15, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->makeOrder($restaurant, $branch, ['total' => 15, 'product' => $burger, 'qty' => 4, 'method' => 'CASH']);

        $token = $this->asSuperAdmin();
        $list = $this->withUserToken($token)->getJson($this->url($restaurant, 'products?sort=best_selling'))
            ->assertOk()->json('data');
        $this->assertSame(2, $list['summary']['total']);
        $this->assertSame(1, $list['summary']['active']);
        $this->assertSame(1, $list['summary']['inactive']);
        $this->assertSame('Burger', $list['products']['data'][0]['name']);
        $this->assertSame(4, (int) $list['products']['data'][0]['units_sold']);

        $inactive = $this->withUserToken($token)->getJson($this->url($restaurant, 'products?status=INACTIVE'))->json('data');
        $this->assertSame(1, $inactive['products']['total']);
        $this->assertSame('Old Soup', $inactive['products']['data'][0]['name']);

        $detail = $this->withUserToken($token)->getJson($this->url($restaurant, "products/{$burger->id}"))
            ->assertOk()->json('data');
        $this->assertSame('Burger', $detail['name']);
        $this->assertSame(4, (int) $detail['sales']['units_sold']);
        $this->assertEquals(60, $detail['sales']['revenue']);
        $this->assertEquals(15, $detail['branches'][0]['price']);

        // A product that belongs to another restaurant is not reachable through this one.
        $this->withUserToken($token)->getJson($this->url($restaurant, "products/{$foreign->id}"))->assertStatus(404);
    }

    public function test_overview_summarises_every_area(): void
    {
        [$restaurant, $branch] = $this->makeRestaurantWithOwner('Overview Co');
        $this->makeProduct($restaurant, 'Thing');
        $c = $this->makeCustomer($restaurant, 'Zed', '+929999');
        $this->makeOrder($restaurant, $branch, ['customer_id' => $c, 'total' => 80, 'status' => 'PENDING']);

        $d = $this->withUserToken($this->asSuperAdmin())->getJson($this->url($restaurant))->assertOk()->json('data');

        $this->assertSame(1, $d['staff']['total']);
        $this->assertSame(1, $d['customers']['total']);
        $this->assertSame(1, $d['orders']['total']);
        $this->assertSame(1, $d['orders']['open_now']);
        $this->assertSame(1, $d['products']['total']);
        $this->assertEquals(80, $d['sales']['all_time']);
        $this->assertSame(1, $d['branches']);
    }

    public function test_the_restaurant_detail_lists_its_own_branches_only(): void
    {
        [$restaurant, $branch] = $this->makeRestaurantWithOwner('Branchy Co');
        $this->makeRestaurantWithOwner('Else Co');

        $data = $this->withUserToken($this->asSuperAdmin())->getJson("/api/v1/super-admin/restaurants/{$restaurant->id}")
            ->assertOk()->json('data');

        $this->assertCount(1, $data['branches']);
        $this->assertSame($branch->id, $data['branches'][0]['id']);
    }
}
