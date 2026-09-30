<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Product;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReportTest extends TestCase
{
    private function makeProduct($restaurant, float $price = 10.00, string $name = 'Margherita'): Product
    {
        $category = Category::create(['restaurant_id' => $restaurant->id, 'name' => 'Menu', 'slug' => 'menu-'.uniqid()]);

        return Product::create([
            'restaurant_id' => $restaurant->id, 'category_id' => $category->id,
            'name' => $name, 'slug' => Str::slug($name).'-'.uniqid(), 'base_price' => $price,
        ]);
    }

    public function test_sales_summary_excludes_cancelled_orders_from_revenue_but_still_counts_them(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Report Co');
        $restaurant->settings->update(['tax_enabled' => true, 'tax_percentage' => 10]);
        $product = $this->makeProduct($restaurant, 10.00);
        $token = $this->actingAsUser($owner);

        $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(201);

        $cancelled = $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(201)->json('data');
        $this->withUserToken($token)->patchJson("/api/v1/orders/{$cancelled['id']}/status", [
            'status' => 'CANCELLED',
        ])->assertOk();

        $report = $this->withUserToken($token)->getJson('/api/v1/reports/sales')->assertOk()->json('data');

        $this->assertSame(2, $report['total_orders']);
        $this->assertSame(1, $report['orders_by_status']['CANCELLED']);
        $this->assertSame(1, $report['order_count']);
        $this->assertEquals(10.0, $report['subtotal']);
        $this->assertEquals(1.0, $report['tax_amount']);
        $this->assertEquals(11.0, $report['net_revenue']);
        $this->assertEquals(11.0, $report['average_order_value']);
    }

    public function test_date_range_filters_orders_outside_the_window(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Report Co');
        $product = $this->makeProduct($restaurant, 10.00);
        $token = $this->actingAsUser($owner);

        $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(201);

        $report = $this->withUserToken($token)
            ->getJson('/api/v1/reports/sales?from=2000-01-01&to=2000-01-02')
            ->assertOk()->json('data');

        $this->assertSame(0, $report['total_orders']);
        $this->assertSame(0, $report['order_count']);
    }

    public function test_an_invalid_date_range_is_rejected(): void
    {
        [, , $owner] = $this->makeRestaurantWithOwner('Report Co');
        $token = $this->actingAsUser($owner);

        $this->withUserToken($token)
            ->getJson('/api/v1/reports/sales?from=2030-01-01&to=2020-01-01')
            ->assertStatus(422)->assertJson(['code' => 'VALIDATION_ERROR']);
    }

    public function test_branch_scoped_user_only_sees_their_own_branchs_numbers(): void
    {
        [$restaurant, $branchA, $owner] = $this->makeRestaurantWithOwner('Report Co');
        $branchB = Branch::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Second Branch', 'branch_code' => 'SECOND', 'status' => 'ACTIVE',
        ]);
        $manager = $this->makeBranchScopedUser($restaurant, $branchA, 'branch-manager');
        $product = $this->makeProduct($restaurant, 10.00);
        $ownerToken = $this->actingAsUser($owner);

        $this->withUserToken($ownerToken)->postJson('/api/v1/orders', [
            'branch_id' => $branchA->id, 'order_type' => 'TAKEAWAY',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(201);
        $this->withUserToken($ownerToken)->postJson('/api/v1/orders', [
            'branch_id' => $branchB->id, 'order_type' => 'TAKEAWAY',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(201);

        $report = $this->withUserToken($this->actingAsUser($manager))
            ->getJson('/api/v1/reports/sales')->assertOk()->json('data');
        $this->assertSame(1, $report['order_count']);
    }

    public function test_branch_scoped_user_cannot_request_another_branchs_report(): void
    {
        [$restaurant, $branchA, ] = $this->makeRestaurantWithOwner('Report Co');
        $branchB = Branch::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Second Branch', 'branch_code' => 'SECOND', 'status' => 'ACTIVE',
        ]);
        $manager = $this->makeBranchScopedUser($restaurant, $branchA, 'branch-manager');
        $token = $this->actingAsUser($manager);

        $this->withUserToken($token)
            ->getJson("/api/v1/reports/sales?branch_id={$branchB->id}")
            ->assertStatus(403)->assertJson(['code' => 'FORBIDDEN']);
    }

    public function test_reports_require_the_reports_feature(): void
    {
        [, , $owner] = $this->makeRestaurantWithOwner('Report Co', planSlug: 'basic');
        $token = $this->actingAsUser($owner);

        $this->withUserToken($token)->getJson('/api/v1/reports/sales')
            ->assertStatus(403)->assertJson(['code' => 'FEATURE_DISABLED']);
    }

    public function test_reports_require_the_reports_view_permission(): void
    {
        [$restaurant, $branch, ] = $this->makeRestaurantWithOwner('Report Co');
        $kitchen = $this->makeBranchScopedUser($restaurant, $branch, 'kitchen');
        $token = $this->actingAsUser($kitchen);

        $this->withUserToken($token)->getJson('/api/v1/reports/sales')
            ->assertStatus(403)->assertJson(['code' => 'FORBIDDEN']);
    }

    public function test_audit_logs_now_also_require_the_reports_feature(): void
    {
        [, , $owner] = $this->makeRestaurantWithOwner('Report Co', planSlug: 'basic');
        $token = $this->actingAsUser($owner);

        $this->withUserToken($token)->getJson('/api/v1/audit-logs')
            ->assertStatus(403)->assertJson(['code' => 'FEATURE_DISABLED']);
    }

    public function test_payments_summary_breaks_down_by_method_and_status(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Report Co');
        $product = $this->makeProduct($restaurant, 10.00);
        $token = $this->actingAsUser($owner);

        $orderA = $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(201)->json('data');
        $this->withUserToken($token)->postJson("/api/v1/orders/{$orderA['id']}/payments", [
            'method' => 'CASH', 'amount' => 10.00,
        ])->assertStatus(201);

        $orderB = $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(201)->json('data');
        $this->withUserToken($token)->postJson("/api/v1/orders/{$orderB['id']}/payments", [
            'method' => 'ONLINE', 'amount' => 10.00,
        ])->assertStatus(201);

        $report = $this->withUserToken($token)->getJson('/api/v1/reports/payments')->assertOk()->json('data');

        $this->assertEquals(10.0, $report['by_method']['CASH']['PAID']['total']);
        $this->assertEquals(10.0, $report['by_method']['ONLINE']['PENDING']['total']);
        $this->assertEquals(10.0, $report['total_collected']);
        $this->assertEquals(10.0, $report['total_pending']);
        $this->assertEquals(0.0, $report['total_refunded']);
    }

    public function test_top_products_orders_by_quantity_sold_and_excludes_cancelled_orders(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Report Co');
        $popular = $this->makeProduct($restaurant, 5.00, 'Popular Pizza');
        $rare = $this->makeProduct($restaurant, 20.00, 'Rare Pizza');
        $token = $this->actingAsUser($owner);

        $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY',
            'items' => [['product_id' => $popular->id, 'quantity' => 5]],
        ])->assertStatus(201);

        $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY',
            'items' => [['product_id' => $rare->id, 'quantity' => 1]],
        ])->assertStatus(201);

        $cancelled = $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY',
            'items' => [['product_id' => $rare->id, 'quantity' => 10]],
        ])->assertStatus(201)->json('data');
        $this->withUserToken($token)->patchJson("/api/v1/orders/{$cancelled['id']}/status", [
            'status' => 'CANCELLED',
        ])->assertOk();

        $top = $this->withUserToken($token)->getJson('/api/v1/reports/top-products')->assertOk()->json('data');

        $this->assertSame('Popular Pizza', $top[0]['product_name']);
        $this->assertSame(5, $top[0]['quantity_sold']);
        $this->assertEquals(25.0, $top[0]['revenue']);
        $this->assertSame('Rare Pizza', $top[1]['product_name']);
        // The cancelled order's 10 units are excluded entirely.
        $this->assertSame(1, $top[1]['quantity_sold']);
    }

    public function test_coupon_usage_report_totals_redemptions_and_discount(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Report Co');
        Coupon::create(['restaurant_id' => $restaurant->id, 'code' => 'SAVE5', 'type' => 'FIXED', 'value' => 5]);
        $product = $this->makeProduct($restaurant, 10.00);
        $token = $this->actingAsUser($owner);

        $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY', 'coupon_code' => 'SAVE5',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(201);

        $report = $this->withUserToken($token)->getJson('/api/v1/reports/coupons')->assertOk()->json('data');

        $this->assertSame('SAVE5', $report[0]['code']);
        $this->assertSame(1, $report[0]['redemptions_count']);
        $this->assertEquals(5.0, $report[0]['total_discount']);
    }
}
