<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\Table;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    private function makeProduct($restaurant, float $price = 10.00): Product
    {
        $category = Category::create(['restaurant_id' => $restaurant->id, 'name' => 'Pizzas', 'slug' => 'pizzas-'.uniqid()]);

        return Product::create([
            'restaurant_id' => $restaurant->id, 'category_id' => $category->id,
            'name' => 'Margherita', 'slug' => 'margherita-'.uniqid(), 'base_price' => $price,
        ]);
    }

    public function test_placing_an_order_notifies_branch_staff_who_can_view_orders(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Notify Co');
        $kitchen = $this->makeBranchScopedUser($restaurant, $branch, 'kitchen');
        $product = $this->makeProduct($restaurant);

        $this->withUserToken($this->actingAsUser($owner))->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(201);

        // Kitchen has orders.view + branch access, so they're notified too,
        // even though the owner (not kitchen) placed the order.
        $kitchenNotifications = $this->withUserToken($this->actingAsUser($kitchen))
            ->getJson('/api/v1/notifications')->assertOk()->json('data.data');
        $this->assertNotEmpty($kitchenNotifications);
        $this->assertSame('order.placed', $kitchenNotifications[0]['data']['type']);
    }

    public function test_staff_at_a_different_branch_are_not_notified(): void
    {
        [$restaurant, $branchA, $owner] = $this->makeRestaurantWithOwner('Notify Co');
        $branchB = Branch::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Second Branch', 'branch_code' => 'SECOND', 'status' => 'ACTIVE',
        ]);
        $kitchenAtB = $this->makeBranchScopedUser($restaurant, $branchB, 'kitchen');
        $product = $this->makeProduct($restaurant);

        $this->withUserToken($this->actingAsUser($owner))->postJson('/api/v1/orders', [
            'branch_id' => $branchA->id, 'order_type' => 'TAKEAWAY',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(201);

        $notifications = $this->withUserToken($this->actingAsUser($kitchenAtB))
            ->getJson('/api/v1/notifications')->assertOk()->json('data.data');
        $this->assertEmpty($notifications);
    }

    public function test_a_customer_placed_order_also_notifies_branch_staff(): void
    {
        [$restaurant, $branch, ] = $this->makeRestaurantWithOwner('Notify Co');
        $waiter = $this->makeBranchScopedUser($restaurant, $branch, 'waiter');
        $product = $this->makeProduct($restaurant);

        $customerToken = $this->postJson("/api/v1/app/auth/register?restaurant={$restaurant->slug}", [
            'phone' => '5559200', 'password' => 'secret123',
        ])->assertStatus(201)->json('data.token');

        $this->withUserToken($customerToken)->postJson('/api/v1/customer/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(201);

        $notifications = $this->withUserToken($this->actingAsUser($waiter))
            ->getJson('/api/v1/notifications')->assertOk()->json('data.data');
        $this->assertNotEmpty($notifications);
    }

    public function test_only_meaningful_status_transitions_notify_staff(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Notify Co');
        $waiter = $this->makeBranchScopedUser($restaurant, $branch, 'waiter');
        $product = $this->makeProduct($restaurant);
        $ownerToken = $this->actingAsUser($owner);

        $order = $this->withUserToken($ownerToken)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(201)->json('data');

        // Clear out the order.placed notification so we can count cleanly from here.
        $this->withUserToken($this->actingAsUser($waiter))->patchJson('/api/v1/notifications/read-all')->assertOk();

        $this->withUserToken($ownerToken)->patchJson("/api/v1/orders/{$order['id']}/status", [
            'status' => 'CONFIRMED',
        ])->assertOk();
        $this->withUserToken($ownerToken)->patchJson("/api/v1/orders/{$order['id']}/status", [
            'status' => 'PREPARING',
        ])->assertOk();

        // PENDING -> CONFIRMED -> PREPARING are internal kitchen steps, not notification-worthy.
        $afterInternalSteps = $this->withUserToken($this->actingAsUser($waiter))
            ->getJson('/api/v1/notifications?unread_only=1')->assertOk()->json('data.data');
        $this->assertEmpty($afterInternalSteps);

        $this->withUserToken($ownerToken)->patchJson("/api/v1/orders/{$order['id']}/status", [
            'status' => 'READY',
        ])->assertOk();

        $afterReady = $this->withUserToken($this->actingAsUser($waiter))
            ->getJson('/api/v1/notifications?unread_only=1')->assertOk()->json('data.data');
        $this->assertCount(1, $afterReady);
        $this->assertSame('order.status_changed', $afterReady[0]['data']['type']);
        $this->assertSame('READY', $afterReady[0]['data']['status']);
    }

    public function test_assigning_a_rider_notifies_only_that_rider(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Notify Co', planSlug: 'premium');
        $restaurant->settings->update(['order_types' => ['DINE_IN', 'TAKEAWAY', 'DELIVERY']]);
        $rider = $this->makeBranchScopedUser($restaurant, $branch, 'rider');
        $otherRider = $this->makeBranchScopedUser($restaurant, $branch, 'rider');
        $product = $this->makeProduct($restaurant);
        $ownerToken = $this->actingAsUser($owner);

        $order = $this->withUserToken($ownerToken)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'DELIVERY', 'delivery_address' => '1 Main St',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(201)->json('data');

        $this->withUserToken($ownerToken)->patchJson("/api/v1/orders/{$order['id']}/rider", [
            'rider_id' => $rider->id,
        ])->assertOk();

        $riderNotifications = $this->withUserToken($this->actingAsUser($rider))
            ->getJson('/api/v1/notifications')->assertOk()->json('data.data');
        $this->assertTrue(collect($riderNotifications)->contains(fn ($n) => $n['data']['type'] === 'order.rider_assigned'));

        $otherRiderNotifications = $this->withUserToken($this->actingAsUser($otherRider))
            ->getJson('/api/v1/notifications')->assertOk()->json('data.data');
        $this->assertFalse(collect($otherRiderNotifications)->contains(fn ($n) => $n['data']['type'] === 'order.rider_assigned'));
    }

    public function test_marking_a_notification_read_and_read_all(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Notify Co');
        $product = $this->makeProduct($restaurant);
        $token = $this->actingAsUser($owner);

        $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(201);

        $unread = $this->withUserToken($token)->getJson('/api/v1/notifications/unread-count')->assertOk();
        $this->assertGreaterThanOrEqual(1, $unread->json('data.unread_count'));

        $first = $this->withUserToken($token)->getJson('/api/v1/notifications')->json('data.data')[0];
        $marked = $this->withUserToken($token)->patchJson("/api/v1/notifications/{$first['id']}/read")
            ->assertOk()->json('data');
        $this->assertNotNull($marked['read_at']);

        $this->withUserToken($token)->patchJson('/api/v1/notifications/read-all')->assertOk();
        $this->withUserToken($token)->getJson('/api/v1/notifications/unread-count')
            ->assertOk()->assertJsonPath('data.unread_count', 0);
    }

    public function test_cannot_mark_another_users_notification_as_read(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Notify Co');
        $kitchen = $this->makeBranchScopedUser($restaurant, $branch, 'kitchen');
        $product = $this->makeProduct($restaurant);

        $this->withUserToken($this->actingAsUser($owner))->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(201);

        $kitchenNotification = $this->withUserToken($this->actingAsUser($kitchen))
            ->getJson('/api/v1/notifications')->json('data.data')[0];

        $this->withUserToken($this->actingAsUser($owner))
            ->patchJson("/api/v1/notifications/{$kitchenNotification['id']}/read")
            ->assertStatus(404);
    }
}
