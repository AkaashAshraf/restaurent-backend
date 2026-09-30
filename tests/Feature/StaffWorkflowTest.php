<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\Table;
use Tests\TestCase;

/**
 * Phase 4: staff workflows built on top of the ordering core — the
 * DELIVERY-only OUT_FOR_DELIVERY status step, rider assignment (and all
 * the ways it's gated), a rider's own scoped view of their orders, the
 * multi-status queue filter kitchen/waiter apps need, and a table's
 * active order surfaced for the waiter app.
 */
class StaffWorkflowTest extends TestCase
{
    private function makeProduct($restaurant, float $price = 10.00): Product
    {
        $category = Category::create(['restaurant_id' => $restaurant->id, 'name' => 'Pizzas', 'slug' => 'pizzas-'.uniqid()]);

        return Product::create([
            'restaurant_id' => $restaurant->id, 'category_id' => $category->id,
            'name' => 'Margherita', 'slug' => 'margherita-'.uniqid(), 'base_price' => $price,
        ]);
    }

    /** Premium plan + DELIVERY enabled at the restaurant's order_types. */
    private function makeDeliveryReadyRestaurant(string $name = 'Staff Co'): array
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner($name, planSlug: 'premium');
        $restaurant->settings->update(['order_types' => ['DINE_IN', 'TAKEAWAY', 'DELIVERY']]);

        return [$restaurant, $branch, $owner];
    }

    private function placeOrder(string $token, Branch $branch, Product $product, string $orderType, array $extra = []): array
    {
        return $this->withUserToken($token)->postJson('/api/v1/orders', array_merge([
            'branch_id' => $branch->id, 'order_type' => $orderType,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ], $extra))->assertStatus(201)->json('data');
    }

    // ---- OUT_FOR_DELIVERY sequencing ----------------------------------

    public function test_delivery_order_passes_through_out_for_delivery_before_completing(): void
    {
        [$restaurant, $branch, $owner] = $this->makeDeliveryReadyRestaurant();
        $product = $this->makeProduct($restaurant);
        $token = $this->actingAsUser($owner);
        $order = $this->placeOrder($token, $branch, $product, 'DELIVERY', ['delivery_address' => '1 Main St']);

        foreach (['CONFIRMED', 'PREPARING', 'READY', 'OUT_FOR_DELIVERY', 'COMPLETED'] as $status) {
            $this->withUserToken($token)->patchJson("/api/v1/orders/{$order['id']}/status", ['status' => $status])
                ->assertOk()->assertJsonPath('data.status', $status);
        }
    }

    public function test_delivery_order_cannot_skip_out_for_delivery(): void
    {
        [$restaurant, $branch, $owner] = $this->makeDeliveryReadyRestaurant();
        $product = $this->makeProduct($restaurant);
        $token = $this->actingAsUser($owner);
        $order = $this->placeOrder($token, $branch, $product, 'DELIVERY', ['delivery_address' => '1 Main St']);

        foreach (['CONFIRMED', 'PREPARING', 'READY'] as $status) {
            $this->withUserToken($token)->patchJson("/api/v1/orders/{$order['id']}/status", ['status' => $status])->assertOk();
        }

        // READY -> COMPLETED directly is a two-step jump for a delivery order.
        $this->withUserToken($token)->patchJson("/api/v1/orders/{$order['id']}/status", ['status' => 'COMPLETED'])
            ->assertStatus(422);
    }

    public function test_non_delivery_order_never_passes_through_out_for_delivery(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Staff Co');
        $product = $this->makeProduct($restaurant);
        $token = $this->actingAsUser($owner);
        $order = $this->placeOrder($token, $branch, $product, 'TAKEAWAY');

        foreach (['CONFIRMED', 'PREPARING', 'READY'] as $status) {
            $this->withUserToken($token)->patchJson("/api/v1/orders/{$order['id']}/status", ['status' => $status])->assertOk();
        }

        // OUT_FOR_DELIVERY is not a valid next step for a takeaway order.
        $this->withUserToken($token)->patchJson("/api/v1/orders/{$order['id']}/status", ['status' => 'OUT_FOR_DELIVERY'])
            ->assertStatus(422);

        // READY -> COMPLETED goes straight through, same as before Phase 4.
        $this->withUserToken($token)->patchJson("/api/v1/orders/{$order['id']}/status", ['status' => 'COMPLETED'])
            ->assertOk()->assertJsonPath('data.status', 'COMPLETED');
    }

    // ---- Rider assignment ----------------------------------------------

    public function test_branch_manager_can_assign_a_rider_to_a_delivery_order(): void
    {
        [$restaurant, $branch, $owner] = $this->makeDeliveryReadyRestaurant();
        $product = $this->makeProduct($restaurant);
        $ownerToken = $this->actingAsUser($owner);
        $order = $this->placeOrder($ownerToken, $branch, $product, 'DELIVERY', ['delivery_address' => '1 Main St']);

        $manager = $this->makeBranchScopedUser($restaurant, $branch, 'branch-manager');
        $rider = $this->makeBranchScopedUser($restaurant, $branch, 'rider');

        $response = $this->withUserToken($this->actingAsUser($manager))
            ->patchJson("/api/v1/orders/{$order['id']}/rider", ['rider_id' => $rider->id])
            ->assertOk();

        $this->assertSame($rider->id, $response->json('data.assigned_rider_id'));
        $this->assertSame($rider->id, $response->json('data.assigned_rider.id'));
    }

    public function test_cannot_assign_a_rider_to_a_non_delivery_order(): void
    {
        [$restaurant, $branch, $owner] = $this->makeDeliveryReadyRestaurant();
        $product = $this->makeProduct($restaurant);
        $ownerToken = $this->actingAsUser($owner);
        $order = $this->placeOrder($ownerToken, $branch, $product, 'TAKEAWAY');

        $manager = $this->makeBranchScopedUser($restaurant, $branch, 'branch-manager');
        $rider = $this->makeBranchScopedUser($restaurant, $branch, 'rider');

        $this->withUserToken($this->actingAsUser($manager))
            ->patchJson("/api/v1/orders/{$order['id']}/rider", ['rider_id' => $rider->id])
            ->assertStatus(422);
    }

    public function test_cannot_assign_a_user_without_the_rider_role(): void
    {
        [$restaurant, $branch, $owner] = $this->makeDeliveryReadyRestaurant();
        $product = $this->makeProduct($restaurant);
        $ownerToken = $this->actingAsUser($owner);
        $order = $this->placeOrder($ownerToken, $branch, $product, 'DELIVERY', ['delivery_address' => '1 Main St']);

        $manager = $this->makeBranchScopedUser($restaurant, $branch, 'branch-manager');
        $waiter = $this->makeBranchScopedUser($restaurant, $branch, 'waiter');

        $this->withUserToken($this->actingAsUser($manager))
            ->patchJson("/api/v1/orders/{$order['id']}/rider", ['rider_id' => $waiter->id])
            ->assertStatus(422);
    }

    public function test_cannot_assign_a_rider_who_has_no_access_to_the_orders_branch(): void
    {
        [$restaurant, $branchA, $owner] = $this->makeDeliveryReadyRestaurant();
        $branchB = Branch::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Second', 'branch_code' => 'SEC', 'status' => 'ACTIVE',
        ]);
        $product = $this->makeProduct($restaurant);
        $ownerToken = $this->actingAsUser($owner);
        $order = $this->placeOrder($ownerToken, $branchA, $product, 'DELIVERY', ['delivery_address' => '1 Main St']);

        $manager = $this->makeBranchScopedUser($restaurant, $branchA, 'branch-manager');
        // Rider is only assigned to branch B, not the order's branch (A).
        $riderElsewhere = $this->makeBranchScopedUser($restaurant, $branchB, 'rider');

        $this->withUserToken($this->actingAsUser($manager))
            ->patchJson("/api/v1/orders/{$order['id']}/rider", ['rider_id' => $riderElsewhere->id])
            ->assertStatus(422);
    }

    public function test_assigning_a_rider_requires_the_assign_rider_permission(): void
    {
        [$restaurant, $branch, $owner] = $this->makeDeliveryReadyRestaurant();
        $product = $this->makeProduct($restaurant);
        $ownerToken = $this->actingAsUser($owner);
        $order = $this->placeOrder($ownerToken, $branch, $product, 'DELIVERY', ['delivery_address' => '1 Main St']);

        // Cashier can view/update orders but was not granted orders.assign_rider.
        $cashier = $this->makeBranchScopedUser($restaurant, $branch, 'cashier');
        $rider = $this->makeBranchScopedUser($restaurant, $branch, 'rider');

        $this->withUserToken($this->actingAsUser($cashier))
            ->patchJson("/api/v1/orders/{$order['id']}/rider", ['rider_id' => $rider->id])
            ->assertStatus(403)
            ->assertJson(['code' => 'FORBIDDEN']);
    }

    public function test_assigning_a_rider_requires_the_rider_app_feature(): void
    {
        // Standard plan has orders.assign_rider available to a branch
        // manager but does not include the RIDER_APP feature at all.
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Staff Co', planSlug: 'standard');
        $product = $this->makeProduct($restaurant);
        $ownerToken = $this->actingAsUser($owner);
        $order = $this->placeOrder($ownerToken, $branch, $product, 'TAKEAWAY');

        $manager = $this->makeBranchScopedUser($restaurant, $branch, 'branch-manager');

        $this->withUserToken($this->actingAsUser($manager))
            ->patchJson("/api/v1/orders/{$order['id']}/rider", ['rider_id' => $owner->id])
            ->assertStatus(403)
            ->assertJson(['code' => 'FEATURE_DISABLED']);
    }

    // ---- Rider-scoped order visibility ----------------------------------

    public function test_rider_only_sees_orders_assigned_to_them(): void
    {
        [$restaurant, $branch, $owner] = $this->makeDeliveryReadyRestaurant();
        $product = $this->makeProduct($restaurant);
        $ownerToken = $this->actingAsUser($owner);

        $orderForRiderOne = $this->placeOrder($ownerToken, $branch, $product, 'DELIVERY', ['delivery_address' => '1 Main St']);
        $orderForRiderTwo = $this->placeOrder($ownerToken, $branch, $product, 'DELIVERY', ['delivery_address' => '2 Main St']);
        $unassignedOrder = $this->placeOrder($ownerToken, $branch, $product, 'DELIVERY', ['delivery_address' => '3 Main St']);

        $riderOne = $this->makeBranchScopedUser($restaurant, $branch, 'rider');
        $riderTwo = $this->makeBranchScopedUser($restaurant, $branch, 'rider');

        $this->withUserToken($ownerToken)->patchJson("/api/v1/orders/{$orderForRiderOne['id']}/rider", ['rider_id' => $riderOne->id])->assertOk();
        $this->withUserToken($ownerToken)->patchJson("/api/v1/orders/{$orderForRiderTwo['id']}/rider", ['rider_id' => $riderTwo->id])->assertOk();

        $response = $this->withUserToken($this->actingAsUser($riderOne))->getJson('/api/v1/orders');
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertSame([$orderForRiderOne['id']], $ids);
        $this->assertNotContains($orderForRiderTwo['id'], $ids);
        $this->assertNotContains($unassignedOrder['id'], $ids);
    }

    // ---- Multi-status queue filter --------------------------------------

    public function test_order_index_supports_a_comma_separated_status_filter(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Staff Co');
        $product = $this->makeProduct($restaurant);
        $token = $this->actingAsUser($owner);

        $pending = $this->placeOrder($token, $branch, $product, 'TAKEAWAY');
        $confirmed = $this->placeOrder($token, $branch, $product, 'TAKEAWAY');
        $this->withUserToken($token)->patchJson("/api/v1/orders/{$confirmed['id']}/status", ['status' => 'CONFIRMED'])->assertOk();
        $completed = $this->placeOrder($token, $branch, $product, 'TAKEAWAY');
        $this->withUserToken($token)->patchJson("/api/v1/orders/{$completed['id']}/status", ['status' => 'CONFIRMED'])->assertOk();
        $this->withUserToken($token)->patchJson("/api/v1/orders/{$completed['id']}/status", ['status' => 'PREPARING'])->assertOk();
        $this->withUserToken($token)->patchJson("/api/v1/orders/{$completed['id']}/status", ['status' => 'READY'])->assertOk();
        $this->withUserToken($token)->patchJson("/api/v1/orders/{$completed['id']}/status", ['status' => 'COMPLETED'])->assertOk();

        $response = $this->withUserToken($token)->getJson('/api/v1/orders?status=PENDING,CONFIRMED');
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($pending['id'], $ids);
        $this->assertContains($confirmed['id'], $ids);
        $this->assertNotContains($completed['id'], $ids);
    }

    // ---- Table active order ---------------------------------------------

    public function test_table_listing_and_detail_surface_the_active_order_and_clear_it_when_completed(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Staff Co');
        $product = $this->makeProduct($restaurant);
        $table = Table::create(['restaurant_id' => $restaurant->id, 'branch_id' => $branch->id, 'table_number' => 'T1']);
        $token = $this->actingAsUser($owner);

        $order = $this->placeOrder($token, $branch, $product, 'DINE_IN', ['table_id' => $table->id]);

        $index = $this->withUserToken($token)->getJson('/api/v1/tables')->assertOk();
        $listed = collect($index->json('data'))->firstWhere('id', $table->id);
        $this->assertSame($order['id'], $listed['active_order']['id']);

        $this->withUserToken($token)->getJson("/api/v1/tables/{$table->id}")
            ->assertOk()->assertJsonPath('data.active_order.id', $order['id']);

        $this->withUserToken($token)->patchJson("/api/v1/orders/{$order['id']}/status", ['status' => 'CANCELLED'])->assertOk();

        $this->withUserToken($token)->getJson("/api/v1/tables/{$table->id}")
            ->assertOk()->assertJsonPath('data.active_order', null);
    }
}
