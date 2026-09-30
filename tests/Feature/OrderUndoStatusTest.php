<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Tests\TestCase;

/**
 * The kitchen's "undo": PATCH /orders/{id}/status/undo moves an order
 * back one step (READY -> PREPARING -> CONFIRMED -> PENDING), but only
 * until it's picked up. Also covers the waiter's name on the order list.
 */
class OrderUndoStatusTest extends TestCase
{
    /** @return array{0: string, 1: array} [token, order] */
    private function placeOrder(string $restaurantName = 'Undo Co'): array
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner($restaurantName);
        $category = Category::create(['restaurant_id' => $restaurant->id, 'name' => 'Mains', 'slug' => 'mains-'.uniqid()]);
        $product = Product::create([
            'restaurant_id' => $restaurant->id, 'category_id' => $category->id,
            'name' => 'Dal Makhani', 'slug' => 'dal-'.uniqid(), 'base_price' => 8.00,
        ]);
        $token = $this->actingAsUser($owner);

        $order = $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id,
            'order_type' => 'TAKEAWAY',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(201)->json('data');

        return [$token, $order];
    }

    private function moveTo(string $token, int $orderId, array $statuses): void
    {
        foreach ($statuses as $status) {
            $this->withUserToken($token)->patchJson("/api/v1/orders/{$orderId}/status", ['status' => $status])
                ->assertStatus(200);
        }
    }

    public function test_undo_walks_back_one_step_at_a_time(): void
    {
        [$token, $order] = $this->placeOrder();
        $this->moveTo($token, $order['id'], ['CONFIRMED', 'PREPARING', 'READY']);

        foreach (['PREPARING', 'CONFIRMED', 'PENDING'] as $expected) {
            $this->withUserToken($token)->patchJson("/api/v1/orders/{$order['id']}/status/undo")
                ->assertStatus(200)
                ->assertJsonPath('data.status', $expected);
        }

        $this->assertDatabaseHas('orders', ['id' => $order['id'], 'status' => 'PENDING']);
        $this->assertDatabaseHas('audit_logs', ['subject_id' => $order['id'], 'action' => 'order.status_undone']);
    }

    public function test_cannot_undo_a_pending_order(): void
    {
        [$token, $order] = $this->placeOrder();

        $this->withUserToken($token)->patchJson("/api/v1/orders/{$order['id']}/status/undo")
            ->assertStatus(422);
    }

    public function test_cannot_undo_once_picked_up(): void
    {
        [$token, $order] = $this->placeOrder();
        $this->moveTo($token, $order['id'], ['CONFIRMED', 'PREPARING', 'READY', 'COMPLETED']);

        $this->withUserToken($token)->patchJson("/api/v1/orders/{$order['id']}/status/undo")
            ->assertStatus(422);
        $this->assertDatabaseHas('orders', ['id' => $order['id'], 'status' => 'COMPLETED']);
    }

    public function test_cannot_undo_a_cancelled_order(): void
    {
        [$token, $order] = $this->placeOrder();
        $this->moveTo($token, $order['id'], ['CONFIRMED', 'CANCELLED']);

        $this->withUserToken($token)->patchJson("/api/v1/orders/{$order['id']}/status/undo")
            ->assertStatus(422);
    }

    public function test_cannot_undo_another_restaurants_order(): void
    {
        [, $order] = $this->placeOrder('First Co');
        [$otherToken] = $this->placeOrder('Second Co');

        $this->withUserToken($otherToken)->patchJson("/api/v1/orders/{$order['id']}/status/undo")
            ->assertStatus(404);
    }

    public function test_order_list_includes_the_waiter_who_placed_it(): void
    {
        [$token, $order] = $this->placeOrder();

        $row = $this->withUserToken($token)->getJson('/api/v1/orders?status=PENDING')
            ->assertStatus(200)
            ->json('data.0');

        $this->assertSame($order['id'], $row['id']);
        $this->assertNotEmpty($row['placed_by']['name']);
        $this->assertArrayNotHasKey('email', $row['placed_by']);
    }
}
