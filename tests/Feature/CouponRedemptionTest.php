<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Coupon;
use App\Models\Product;
use Tests\TestCase;

class CouponRedemptionTest extends TestCase
{
    private function makeProduct($restaurant, float $price = 10.00): Product
    {
        $category = Category::create(['restaurant_id' => $restaurant->id, 'name' => 'Pizzas', 'slug' => 'pizzas-'.uniqid()]);

        return Product::create([
            'restaurant_id' => $restaurant->id, 'category_id' => $category->id,
            'name' => 'Margherita', 'slug' => 'margherita-'.uniqid(), 'base_price' => $price,
        ]);
    }

    private function makeCoupon($restaurant, array $overrides = []): Coupon
    {
        return Coupon::create($overrides + [
            'restaurant_id' => $restaurant->id, 'code' => 'TESTCODE', 'type' => 'PERCENTAGE', 'value' => 10,
        ]);
    }

    public function test_percentage_coupon_discounts_the_subtotal_and_caps_at_max_discount(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Coupon Co');
        $this->makeCoupon($restaurant, ['type' => 'PERCENTAGE', 'value' => 50, 'max_discount_amount' => 8]);
        $product = $this->makeProduct($restaurant, 100.00);
        $token = $this->actingAsUser($owner);

        // 50% of 100 would be 50, but max_discount_amount caps it at 8.
        $response = $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY', 'coupon_code' => 'testcode',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $response->assertStatus(201);
        $this->assertEquals(100.0, $response->json('data.subtotal'));
        $this->assertEquals(8.0, $response->json('data.discount_amount'));
        $this->assertEquals(92.0, $response->json('data.total_amount'));
    }

    public function test_fixed_coupon_never_discounts_more_than_the_subtotal(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Coupon Co');
        $this->makeCoupon($restaurant, ['type' => 'FIXED', 'value' => 50]);
        $product = $this->makeProduct($restaurant, 10.00);
        $token = $this->actingAsUser($owner);

        $response = $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY', 'coupon_code' => 'TESTCODE',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $response->assertStatus(201);
        $this->assertEquals(10.0, $response->json('data.discount_amount'));
        $this->assertEquals(0.0, $response->json('data.total_amount'));
    }

    public function test_coupon_below_its_minimum_order_amount_is_rejected(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Coupon Co');
        $this->makeCoupon($restaurant, ['min_order_amount' => 50]);
        $product = $this->makeProduct($restaurant, 10.00);
        $token = $this->actingAsUser($owner);

        $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY', 'coupon_code' => 'TESTCODE',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(422)->assertJson(['code' => 'INVALID_COUPON']);
    }

    public function test_unknown_coupon_code_is_rejected(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Coupon Co');
        $product = $this->makeProduct($restaurant);
        $token = $this->actingAsUser($owner);

        $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY', 'coupon_code' => 'DOES-NOT-EXIST',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(422)->assertJson(['code' => 'INVALID_COUPON']);
    }

    public function test_inactive_and_expired_coupons_are_rejected(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Coupon Co');
        $this->makeCoupon($restaurant, ['code' => 'INACTIVE1', 'is_active' => false]);
        $this->makeCoupon($restaurant, ['code' => 'EXPIRED1', 'valid_until' => now()->subDay()]);
        $product = $this->makeProduct($restaurant);
        $token = $this->actingAsUser($owner);

        $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY', 'coupon_code' => 'INACTIVE1',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(422);

        $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY', 'coupon_code' => 'EXPIRED1',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(422);
    }

    public function test_usage_limit_is_enforced_across_orders(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Coupon Co');
        $this->makeCoupon($restaurant, ['usage_limit' => 1]);
        $product = $this->makeProduct($restaurant);
        $token = $this->actingAsUser($owner);

        $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY', 'coupon_code' => 'TESTCODE',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(201);

        $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY', 'coupon_code' => 'TESTCODE',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(422)->assertJson(['code' => 'INVALID_COUPON']);
    }

    public function test_per_customer_limit_blocks_a_repeat_customer_but_not_a_different_one(): void
    {
        [$restaurant, $branch, ] = $this->makeRestaurantWithOwner('Coupon Co');
        $restaurant->settings->update(['order_types' => ['DINE_IN', 'TAKEAWAY', 'DELIVERY']]);
        $this->makeCoupon($restaurant, ['per_customer_limit' => 1]);
        $product = $this->makeProduct($restaurant);

        $tokenA = $this->postJson("/api/v1/app/auth/register?restaurant={$restaurant->slug}", [
            'phone' => '5559001', 'password' => 'secret123',
        ])->assertStatus(201)->json('data.token');
        $tokenB = $this->postJson("/api/v1/app/auth/register?restaurant={$restaurant->slug}", [
            'phone' => '5559002', 'password' => 'secret123',
        ])->assertStatus(201)->json('data.token');

        $this->withUserToken($tokenA)->postJson('/api/v1/customer/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY', 'coupon_code' => 'TESTCODE',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(201);

        // Same customer, same coupon, again -> rejected.
        $this->withUserToken($tokenA)->postJson('/api/v1/customer/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY', 'coupon_code' => 'TESTCODE',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(422)->assertJson(['code' => 'INVALID_COUPON']);

        // A different customer at the same restaurant is unaffected.
        $this->withUserToken($tokenB)->postJson('/api/v1/customer/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY', 'coupon_code' => 'TESTCODE',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(201);
    }

    public function test_a_staff_placed_order_with_no_customer_only_counts_against_the_overall_usage_limit(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Coupon Co');
        // per_customer_limit is set, but a staff order with no customer_id
        // has no customer to check that against — only usage_limit applies.
        $this->makeCoupon($restaurant, ['per_customer_limit' => 1, 'usage_limit' => 2]);
        $product = $this->makeProduct($restaurant);
        $token = $this->actingAsUser($owner);

        $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY', 'coupon_code' => 'TESTCODE',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(201);

        $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY', 'coupon_code' => 'TESTCODE',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(201);

        // Third one exceeds usage_limit=2.
        $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY', 'coupon_code' => 'TESTCODE',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(422);
    }

    public function test_applying_a_coupon_requires_the_coupons_feature(): void
    {
        // Basic plan does not include COUPONS.
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Coupon Co', planSlug: 'basic');
        $this->makeCoupon($restaurant);
        $product = $this->makeProduct($restaurant);
        $token = $this->actingAsUser($owner);

        $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY', 'coupon_code' => 'TESTCODE',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(403)->assertJson(['code' => 'FEATURE_DISABLED']);
    }

    public function test_cannot_use_another_restaurants_coupon_code(): void
    {
        [$restaurantA, $branchA, $ownerA] = $this->makeRestaurantWithOwner('Coupon A');
        [$restaurantB, , ] = $this->makeRestaurantWithOwner('Coupon B');
        $this->makeCoupon($restaurantB, ['code' => 'BONLY']);
        $product = $this->makeProduct($restaurantA);
        $token = $this->actingAsUser($ownerA);

        $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branchA->id, 'order_type' => 'TAKEAWAY', 'coupon_code' => 'BONLY',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(422)->assertJson(['code' => 'INVALID_COUPON']);
    }

    public function test_an_order_without_a_coupon_code_is_unaffected(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Coupon Co');
        $this->makeCoupon($restaurant);
        $product = $this->makeProduct($restaurant, 10.00);
        $token = $this->actingAsUser($owner);

        $response = $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $response->assertStatus(201);
        $this->assertEquals(0.0, $response->json('data.discount_amount'));
        $this->assertEquals(10.0, $response->json('data.total_amount'));
    }
}
