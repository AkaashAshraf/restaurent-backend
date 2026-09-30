<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Tests\TestCase;

/**
 * Kitchen tickets: every order starts with ticket 1 (its original items);
 * items added after the kitchen has accepted the order go on their own
 * ticket, so the kitchen only ever sees what's new. The waiter marks a
 * ticket picked up; undo works until then. The bill is still one order.
 */
class KitchenTicketTest extends TestCase
{
    private $restaurant;
    private $branch;
    private $product;
    private string $waiterToken;
    private string $kitchenToken;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->restaurant, $this->branch] = $this->makeRestaurantWithOwner('Ticket Co');
        $category = Category::create(['restaurant_id' => $this->restaurant->id, 'name' => 'Mains', 'slug' => 'mains-'.uniqid()]);
        $this->product = Product::create([
            'restaurant_id' => $this->restaurant->id, 'category_id' => $category->id,
            'name' => 'Butter Chicken', 'slug' => 'bc-'.uniqid(), 'base_price' => 10.00,
        ]);

        $waiter = $this->makeBranchScopedUser($this->restaurant, $this->branch, 'waiter');
        $waiter->update(['name' => 'Ravi']);
        $this->waiterToken = $this->actingAsUser($waiter);
        $this->kitchenToken = $this->actingAsUser($this->makeBranchScopedUser($this->restaurant, $this->branch, 'kitchen'));
    }

    private function placeOrder(int $quantity = 2): array
    {
        return $this->withUserToken($this->waiterToken)->postJson('/api/v1/orders', [
            'branch_id' => $this->branch->id,
            'order_type' => 'TAKEAWAY',
            'items' => [['product_id' => $this->product->id, 'quantity' => $quantity]],
        ])->assertStatus(201)->json('data');
    }

    private function addItems(int $orderId, int $quantity = 1): void
    {
        $this->withUserToken($this->waiterToken)->postJson("/api/v1/orders/{$orderId}/items", [
            'items' => [['product_id' => $this->product->id, 'quantity' => $quantity, 'notes' => 'added later']],
        ])->assertStatus(201);
    }

    private function tickets(string $status = 'PENDING,CONFIRMED,PREPARING,READY'): array
    {
        return $this->withUserToken($this->kitchenToken)->getJson("/api/v1/kitchen-tickets?status={$status}")
            ->assertStatus(200)->json('data');
    }

    private function move(string $token, int $ticketId, string $status, int $expect = 200)
    {
        return $this->withUserToken($token)
            ->patchJson("/api/v1/kitchen-tickets/{$ticketId}/status", ['status' => $status])
            ->assertStatus($expect);
    }

    private function orderStatus(int $orderId): string
    {
        return $this->withUserToken($this->waiterToken)->getJson("/api/v1/orders/{$orderId}")->json('data.status');
    }

    public function test_a_new_order_gets_ticket_one_with_all_its_items_and_order_details(): void
    {
        $order = $this->placeOrder(2);

        $tickets = $this->tickets();
        $this->assertCount(1, $tickets);
        $this->assertSame(1, $tickets[0]['sequence']);
        $this->assertSame('PENDING', $tickets[0]['status']);
        $this->assertSame(2, (int) $tickets[0]['items'][0]['quantity']);
        $this->assertSame($order['order_number'], $tickets[0]['order']['order_number']);
        $this->assertSame('Ravi', $tickets[0]['order']['placed_by']['name']);
    }

    public function test_items_added_before_the_kitchen_accepts_join_ticket_one(): void
    {
        $order = $this->placeOrder();
        $this->addItems($order['id']);

        $tickets = $this->tickets();
        $this->assertCount(1, $tickets);
        $this->assertCount(2, $tickets[0]['items']);
    }

    public function test_items_added_after_the_kitchen_accepts_get_their_own_ticket(): void
    {
        $order = $this->placeOrder(2);
        $main = $this->tickets()[0];
        $this->move($this->kitchenToken, $main['id'], 'CONFIRMED');

        $this->addItems($order['id'], 1);

        $tickets = $this->tickets();
        $this->assertCount(2, $tickets);
        $addOn = collect($tickets)->firstWhere('sequence', 2);
        $this->assertSame('PENDING', $addOn['status']);
        $this->assertCount(1, $addOn['items']);
        $this->assertSame('added later', $addOn['items'][0]['notes']);
        // The order itself is untouched.
        $this->assertSame('CONFIRMED', $this->orderStatus($order['id']));
    }

    public function test_the_main_ticket_moves_the_order_along(): void
    {
        $order = $this->placeOrder();
        $main = $this->tickets()[0];

        foreach (['CONFIRMED', 'PREPARING', 'READY'] as $status) {
            $this->move($this->kitchenToken, $main['id'], $status)->assertJsonPath('data.status', $status);
            $this->assertSame($status, $this->orderStatus($order['id']));
        }
    }

    public function test_an_add_on_ticket_runs_on_its_own_and_the_order_stays_served(): void
    {
        $order = $this->placeOrder();
        $main = $this->tickets()[0];
        foreach (['CONFIRMED', 'PREPARING', 'READY'] as $status) {
            $this->move($this->kitchenToken, $main['id'], $status);
        }
        $this->move($this->waiterToken, $main['id'], 'PICKED_UP');

        $this->addItems($order['id']);
        $tickets = $this->tickets();
        // Only the new round is on the kitchen screen — the served food isn't.
        $this->assertCount(1, $tickets);
        $this->assertSame(2, $tickets[0]['sequence']);

        foreach (['CONFIRMED', 'PREPARING', 'READY'] as $status) {
            $this->move($this->kitchenToken, $tickets[0]['id'], $status);
        }
        $this->assertSame('READY', $this->orderStatus($order['id']));

        $this->move($this->waiterToken, $tickets[0]['id'], 'PICKED_UP');
        $this->assertCount(0, $this->tickets());

        $summary = $this->withUserToken($this->waiterToken)->getJson('/api/v1/orders?status=READY')->json('data.0.kitchen_tickets');
        $this->assertSame(['PICKED_UP', 'PICKED_UP'], array_column($summary, 'status'));
    }

    public function test_undo_works_until_pickup_and_not_after(): void
    {
        $order = $this->placeOrder();
        $main = $this->tickets()[0];
        foreach (['CONFIRMED', 'PREPARING', 'READY'] as $status) {
            $this->move($this->kitchenToken, $main['id'], $status);
        }

        $this->withUserToken($this->kitchenToken)->patchJson("/api/v1/kitchen-tickets/{$main['id']}/undo")
            ->assertStatus(200)->assertJsonPath('data.status', 'PREPARING');
        $this->assertSame('PREPARING', $this->orderStatus($order['id']));

        $this->move($this->kitchenToken, $main['id'], 'READY');
        $this->move($this->waiterToken, $main['id'], 'PICKED_UP');

        $this->withUserToken($this->kitchenToken)->patchJson("/api/v1/kitchen-tickets/{$main['id']}/undo")
            ->assertStatus(422);
        $this->withUserToken($this->kitchenToken)->patchJson("/api/v1/orders/{$order['id']}/status/undo")
            ->assertStatus(422);
    }

    public function test_steps_cannot_be_skipped(): void
    {
        $this->placeOrder();
        $main = $this->tickets()[0];

        $this->move($this->kitchenToken, $main['id'], 'READY', 422);
        $this->move($this->kitchenToken, $main['id'], 'PICKED_UP', 422);
    }

    public function test_status_changes_made_on_the_order_keep_ticket_one_in_step(): void
    {
        $order = $this->placeOrder();

        $this->withUserToken($this->waiterToken)
            ->patchJson("/api/v1/orders/{$order['id']}/status", ['status' => 'CONFIRMED'])
            ->assertStatus(200);

        $this->assertSame('CONFIRMED', $this->tickets()[0]['status']);
    }

    public function test_cancelling_the_order_cancels_its_open_tickets(): void
    {
        $order = $this->placeOrder();

        $this->withUserToken($this->waiterToken)
            ->patchJson("/api/v1/orders/{$order['id']}/status", ['status' => 'CANCELLED'])
            ->assertStatus(200);

        $this->assertCount(0, $this->tickets());
        $this->assertCount(1, $this->tickets('CANCELLED'));
    }

    public function test_checkout_picks_up_ready_food_but_leaves_a_cooking_add_on_in_the_kitchen(): void
    {
        $order = $this->placeOrder();
        $main = $this->tickets()[0];
        foreach (['CONFIRMED', 'PREPARING', 'READY'] as $status) {
            $this->move($this->kitchenToken, $main['id'], $status);
        }
        $this->addItems($order['id']);
        $addOn = collect($this->tickets())->firstWhere('sequence', 2);
        $this->move($this->kitchenToken, $addOn['id'], 'CONFIRMED');
        $this->move($this->kitchenToken, $addOn['id'], 'PREPARING');

        $this->withUserToken($this->waiterToken)
            ->patchJson("/api/v1/orders/{$order['id']}/status", ['status' => 'COMPLETED'])
            ->assertStatus(200);

        $open = $this->tickets();
        $this->assertCount(1, $open);
        $this->assertSame(2, $open[0]['sequence']);
        $this->assertSame('PREPARING', $open[0]['status']);

        // The kitchen can still finish it and the waiter can still collect it.
        $this->move($this->kitchenToken, $addOn['id'], 'READY');
        $this->move($this->waiterToken, $addOn['id'], 'PICKED_UP');
        $this->assertCount(0, $this->tickets());
    }

    public function test_another_restaurant_cannot_see_or_touch_the_tickets(): void
    {
        $this->placeOrder();
        $ticketId = $this->tickets()[0]['id'];

        [, , $otherOwner] = $this->makeRestaurantWithOwner('Other Co');
        $otherToken = $this->actingAsUser($otherOwner);

        $this->assertCount(0, $this->withUserToken($otherToken)->getJson('/api/v1/kitchen-tickets')->json('data'));
        $this->move($otherToken, $ticketId, 'CONFIRMED', 404);
    }
}
