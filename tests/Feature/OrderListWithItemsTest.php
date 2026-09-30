<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Tests\TestCase;

/**
 * GET /orders?with_items=1 is what the kitchen display uses so every
 * ticket's items arrive in one request. The plain list must stay light
 * (no items) for everyone who doesn't ask.
 */
class OrderListWithItemsTest extends TestCase
{
    private function placeOrder(): array
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Kitchen Co');
        $category = Category::create(['restaurant_id' => $restaurant->id, 'name' => 'Mains', 'slug' => 'mains-'.uniqid()]);
        $product = Product::create([
            'restaurant_id' => $restaurant->id, 'category_id' => $category->id,
            'name' => 'Burger', 'slug' => 'burger-'.uniqid(), 'base_price' => 12.50,
        ]);
        $token = $this->actingAsUser($owner);

        $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id,
            'order_type' => 'TAKEAWAY',
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
        ])->assertStatus(201);

        return [$token];
    }

    public function test_list_includes_items_when_asked(): void
    {
        [$token] = $this->placeOrder();

        $response = $this->withUserToken($token)->getJson('/api/v1/orders?status=PENDING&with_items=1');

        $response->assertStatus(200);
        $this->assertSame('Burger', $response->json('data.0.items.0.product_name'));
        $this->assertSame(3, (int) $response->json('data.0.items.0.quantity'));
        $this->assertIsArray($response->json('data.0.items.0.modifiers'));
    }

    public function test_plain_list_stays_light(): void
    {
        [$token] = $this->placeOrder();

        $response = $this->withUserToken($token)->getJson('/api/v1/orders?status=PENDING');

        $response->assertStatus(200);
        $this->assertArrayNotHasKey('items', $response->json('data.0'));
    }
}
