<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Tests\TestCase;

/**
 * Covers the two ways an order can change after it's placed but before
 * (or, for returns, even after) checkout: a waiter tacking on more
 * items, and a waiter recording that some quantity of an item came
 * back with a reason. Both funnel through OrderService::repriceOrder(),
 * so the assertions here double as a check that subtotal/tax/total
 * stay in lockstep with createOrder()'s own math.
 */
class OrderAmendmentTest extends TestCase
{
    private function makeProduct($restaurant, float $price = 10.00): Product
    {
        $category = Category::create(['restaurant_id' => $restaurant->id, 'name' => 'Pizzas', 'slug' => 'pizzas-'.uniqid()]);

        return Product::create([
            'restaurant_id' => $restaurant->id, 'category_id' => $category->id,
            'name' => 'Margherita', 'slug' => 'margherita-'.uniqid(), 'base_price' => $price,
        ]);
    }

    private function createOrder($token, $branch, $product, int $quantity = 2): array
    {
        $response = $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id,
            'order_type' => 'TAKEAWAY',
            'items' => [['product_id' => $product->id, 'quantity' => $quantity]],
        ])->assertStatus(201);

        return $response->json('data');
    }

    public function test_waiter_can_add_items_to_an_order_before_checkout(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Order Co');
        $product = $this->makeProduct($restaurant, 10.00);
        $token = $this->actingAsUser($owner);

        $order = $this->createOrder($token, $branch, $product, 2); // 20.00

        $response = $this->withUserToken($token)->postJson("/api/v1/orders/{$order['id']}/items", [
            'items' => [['product_id' => $product->id, 'quantity' => 1]], // +10.00
        ]);

        $response->assertStatus(201);
        $this->assertEquals(30.0, $response->json('data.subtotal'));
        $this->assertEquals(30.0, $response->json('data.total_amount'));
        $this->assertCount(2, $response->json('data.items'));
    }

    public function test_cannot_add_items_to_a_completed_order(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Order Co');
        $product = $this->makeProduct($restaurant, 10.00);
        $token = $this->actingAsUser($owner);

        $order = $this->createOrder($token, $branch, $product, 1);
        foreach (['CONFIRMED', 'PREPARING', 'READY', 'COMPLETED'] as $status) {
            $this->withUserToken($token)->patchJson("/api/v1/orders/{$order['id']}/status", ['status' => $status])
                ->assertStatus(200);
        }

        $response = $this->withUserToken($token)->postJson("/api/v1/orders/{$order['id']}/items", [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $response->assertStatus(422);
    }

    public function test_waiter_can_return_part_of_an_item_with_a_reason_and_the_total_drops(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Order Co');
        $product = $this->makeProduct($restaurant, 10.00);
        $token = $this->actingAsUser($owner);

        $order = $this->createOrder($token, $branch, $product, 3); // 30.00
        $itemId = $order['items'][0]['id'];

        $response = $this->withUserToken($token)->postJson("/api/v1/orders/{$order['id']}/items/{$itemId}/returns", [
            'quantity' => 1,
            'reason' => 'Customer said it arrived cold',
        ]);

        $response->assertStatus(201);
        $this->assertEquals(20.0, $response->json('data.subtotal'));
        $this->assertEquals(20.0, $response->json('data.total_amount'));
        $this->assertSame(1, $response->json('data.items.0.returned_quantity'));
        $this->assertCount(1, $response->json('data.items.0.returns'));
        $this->assertSame('Customer said it arrived cold', $response->json('data.items.0.returns.0.reason'));
    }

    public function test_cannot_return_more_than_was_ordered(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Order Co');
        $product = $this->makeProduct($restaurant, 10.00);
        $token = $this->actingAsUser($owner);

        $order = $this->createOrder($token, $branch, $product, 2);
        $itemId = $order['items'][0]['id'];

        $response = $this->withUserToken($token)->postJson("/api/v1/orders/{$order['id']}/items/{$itemId}/returns", [
            'quantity' => 5,
            'reason' => 'Too many',
        ]);

        $response->assertStatus(422);
    }

    public function test_cannot_return_items_on_a_cancelled_order(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Order Co');
        $product = $this->makeProduct($restaurant, 10.00);
        $token = $this->actingAsUser($owner);

        $order = $this->createOrder($token, $branch, $product, 1);
        $itemId = $order['items'][0]['id'];
        $this->withUserToken($token)->patchJson("/api/v1/orders/{$order['id']}/status", ['status' => 'CANCELLED'])
            ->assertStatus(200);

        $response = $this->withUserToken($token)->postJson("/api/v1/orders/{$order['id']}/items/{$itemId}/returns", [
            'quantity' => 1,
            'reason' => 'Never mind',
        ]);

        $response->assertStatus(422);
    }

    public function test_return_is_allowed_even_on_an_already_completed_order(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Order Co');
        $product = $this->makeProduct($restaurant, 10.00);
        $token = $this->actingAsUser($owner);

        $order = $this->createOrder($token, $branch, $product, 2);
        $itemId = $order['items'][0]['id'];
        $this->withUserToken($token)->patchJson("/api/v1/orders/{$order['id']}/status", ['status' => 'CONFIRMED'])->assertStatus(200);
        $this->withUserToken($token)->patchJson("/api/v1/orders/{$order['id']}/status", ['status' => 'PREPARING'])->assertStatus(200);
        $this->withUserToken($token)->patchJson("/api/v1/orders/{$order['id']}/status", ['status' => 'READY'])->assertStatus(200);
        $this->withUserToken($token)->patchJson("/api/v1/orders/{$order['id']}/status", ['status' => 'COMPLETED'])->assertStatus(200);

        $response = $this->withUserToken($token)->postJson("/api/v1/orders/{$order['id']}/items/{$itemId}/returns", [
            'quantity' => 1,
            'reason' => 'Found it after paying',
        ]);

        $response->assertStatus(201);
        $this->assertEquals(10.0, $response->json('data.total_amount'));
    }
}
