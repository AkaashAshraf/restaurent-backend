<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Tests\TestCase;

class CustomerOrderingTest extends TestCase
{
    private function makeProduct($restaurant, float $price = 10.00): Product
    {
        $category = Category::create(['restaurant_id' => $restaurant->id, 'name' => 'Pizzas', 'slug' => 'pizzas-'.uniqid()]);

        return Product::create([
            'restaurant_id' => $restaurant->id, 'category_id' => $category->id,
            'name' => 'Margherita', 'slug' => 'margherita-'.uniqid(), 'base_price' => $price,
        ]);
    }

    private function registerCustomer($restaurant, string $phone = '5552000'): string
    {
        return $this->postJson("/api/v1/app/auth/register?restaurant={$restaurant->slug}", [
            'phone' => $phone, 'password' => 'secret123',
        ])->assertStatus(201)->json('data.token');
    }

    public function test_customer_can_place_a_takeaway_order_and_then_see_it_in_their_own_history(): void
    {
        [$restaurant, $branch, ] = $this->makeRestaurantWithOwner('Order Co');
        $product = $this->makeProduct($restaurant);
        $token = $this->registerCustomer($restaurant);

        $order = $this->withUserToken($token)->postJson('/api/v1/customer/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY',
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ])->assertStatus(201)->json('data');

        $this->assertEquals(20.0, $order['subtotal']);
        $this->assertNull($order['placed_by_user_id'] ?? null);

        $index = $this->withUserToken($token)->getJson('/api/v1/customer/orders')->assertOk();
        $this->assertSame([$order['id']], collect($index->json('data'))->pluck('id')->all());

        $this->withUserToken($token)->getJson("/api/v1/customer/orders/{$order['id']}")
            ->assertOk()->assertJsonPath('data.id', $order['id']);
    }

    public function test_customer_cannot_see_another_customers_order(): void
    {
        [$restaurant, $branch, ] = $this->makeRestaurantWithOwner('Order Co');
        $product = $this->makeProduct($restaurant);
        $tokenA = $this->registerCustomer($restaurant, '5552001');
        $tokenB = $this->registerCustomer($restaurant, '5552002');

        $orderA = $this->withUserToken($tokenA)->postJson('/api/v1/customer/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(201)->json('data');

        $this->withUserToken($tokenB)->getJson("/api/v1/customer/orders/{$orderA['id']}")->assertStatus(404);
        $this->withUserToken($tokenB)->getJson('/api/v1/customer/orders')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_placing_an_order_requires_the_online_ordering_feature(): void
    {
        // Basic plan has neither CUSTOMER_APP nor ONLINE_ORDERING, so this
        // never even reaches the ONLINE_ORDERING check — confirming the
        // broader gate on the whole /customer group already blocks it.
        [$restaurant, $branch, ] = $this->makeRestaurantWithOwner('Order Co', planSlug: 'basic');
        $product = $this->makeProduct($restaurant);
        $token = $this->registerCustomer($restaurant);

        $this->withUserToken($token)->postJson('/api/v1/customer/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(403)->assertJson(['code' => 'FEATURE_DISABLED']);
    }

    public function test_customer_can_order_delivery_using_a_saved_address(): void
    {
        [$restaurant, $branch, ] = $this->makeRestaurantWithOwner('Order Co', planSlug: 'premium');
        $restaurant->settings->update(['order_types' => ['DINE_IN', 'TAKEAWAY', 'DELIVERY']]);
        $product = $this->makeProduct($restaurant);
        $token = $this->registerCustomer($restaurant);

        $address = $this->withUserToken($token)->postJson('/api/v1/customer/addresses', [
            'label' => 'Home', 'full_address' => '42 Main St', 'latitude' => 24.86, 'longitude' => 67.00,
        ])->assertStatus(201)->json('data');

        $order = $this->withUserToken($token)->postJson('/api/v1/customer/orders', [
            'branch_id' => $branch->id, 'order_type' => 'DELIVERY',
            'customer_address_id' => $address['id'],
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(201)->json('data');

        $this->assertSame('42 Main St', $order['delivery_address']);
    }

    public function test_customer_cannot_use_another_customers_saved_address(): void
    {
        [$restaurant, $branch, ] = $this->makeRestaurantWithOwner('Order Co', planSlug: 'premium');
        $restaurant->settings->update(['order_types' => ['DINE_IN', 'TAKEAWAY', 'DELIVERY']]);
        $product = $this->makeProduct($restaurant);
        $tokenA = $this->registerCustomer($restaurant, '5552003');
        $tokenB = $this->registerCustomer($restaurant, '5552004');

        $addressA = $this->withUserToken($tokenA)->postJson('/api/v1/customer/addresses', [
            'full_address' => '1 Main St',
        ])->assertStatus(201)->json('data');

        $this->withUserToken($tokenB)->postJson('/api/v1/customer/orders', [
            'branch_id' => $branch->id, 'order_type' => 'DELIVERY',
            'customer_address_id' => $addressA['id'],
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(404);
    }
}
