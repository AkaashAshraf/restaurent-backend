<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Table;
use Tests\TestCase;

class OrderLifecycleTest extends TestCase
{
    private function placeTakeawayOrder($restaurant, $branch, $owner): array
    {
        $category = Category::create(['restaurant_id' => $restaurant->id, 'name' => 'Pizzas', 'slug' => 'pizzas-'.uniqid()]);
        $product = Product::create([
            'restaurant_id' => $restaurant->id, 'category_id' => $category->id,
            'name' => 'Margherita', 'slug' => 'margherita-'.uniqid(), 'base_price' => 10,
        ]);
        $token = $this->actingAsUser($owner);

        $order = $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(201)->json('data');

        return [$order, $token];
    }

    public function test_status_moves_forward_one_step_at_a_time(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Lifecycle Co');
        [$order, $token] = $this->placeTakeawayOrder($restaurant, $branch, $owner);

        $this->withUserToken($token)->patchJson("/api/v1/orders/{$order['id']}/status", ['status' => 'CONFIRMED'])
            ->assertOk()->assertJsonPath('data.status', 'CONFIRMED');

        $this->withUserToken($token)->patchJson("/api/v1/orders/{$order['id']}/status", ['status' => 'PREPARING'])
            ->assertOk()->assertJsonPath('data.status', 'PREPARING');
    }

    public function test_cannot_skip_a_status_or_move_backward(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Lifecycle Co');
        [$order, $token] = $this->placeTakeawayOrder($restaurant, $branch, $owner);

        // PENDING -> READY skips CONFIRMED/PREPARING.
        $this->withUserToken($token)->patchJson("/api/v1/orders/{$order['id']}/status", ['status' => 'READY'])
            ->assertStatus(422);

        $this->withUserToken($token)->patchJson("/api/v1/orders/{$order['id']}/status", ['status' => 'CONFIRMED'])->assertOk();
        $this->withUserToken($token)->patchJson("/api/v1/orders/{$order['id']}/status", ['status' => 'PENDING'])
            ->assertStatus(422);
    }

    public function test_cannot_change_status_of_a_completed_or_cancelled_order(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Lifecycle Co');
        [$order, $token] = $this->placeTakeawayOrder($restaurant, $branch, $owner);

        $this->withUserToken($token)->patchJson("/api/v1/orders/{$order['id']}/status", ['status' => 'CANCELLED'])->assertOk();
        $this->withUserToken($token)->patchJson("/api/v1/orders/{$order['id']}/status", ['status' => 'CONFIRMED'])
            ->assertStatus(422);
    }

    public function test_completing_or_cancelling_a_dine_in_order_releases_its_table(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Lifecycle Co');
        $category = Category::create(['restaurant_id' => $restaurant->id, 'name' => 'Pizzas', 'slug' => 'pizzas-'.uniqid()]);
        $product = Product::create([
            'restaurant_id' => $restaurant->id, 'category_id' => $category->id,
            'name' => 'Margherita', 'slug' => 'margherita-'.uniqid(), 'base_price' => 10,
        ]);
        $table = Table::create(['restaurant_id' => $restaurant->id, 'branch_id' => $branch->id, 'table_number' => 'T1']);
        $token = $this->actingAsUser($owner);

        $order = $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'DINE_IN', 'table_id' => $table->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(201)->json('data');

        $this->assertSame('OCCUPIED', $table->fresh()->status->value);

        $this->withUserToken($token)->patchJson("/api/v1/orders/{$order['id']}/status", ['status' => 'CANCELLED'])->assertOk();

        $this->assertSame('AVAILABLE', $table->fresh()->status->value);
    }

    public function test_kitchen_staff_can_update_status_but_not_place_a_delivery_order(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Lifecycle Co');
        [$order, ] = $this->placeTakeawayOrder($restaurant, $branch, $owner);

        $kitchen = $this->makeBranchScopedUser($restaurant, $branch, 'kitchen');
        $kitchenToken = $this->actingAsUser($kitchen);

        $this->withUserToken($kitchenToken)->patchJson("/api/v1/orders/{$order['id']}/status", ['status' => 'CONFIRMED'])
            ->assertOk();

        $this->withUserToken($kitchenToken)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY', 'items' => [],
        ])->assertStatus(403); // kitchen has no orders.create permission
    }

    public function test_branch_scoped_user_cannot_view_or_update_an_order_from_another_branch(): void
    {
        [$restaurant, $branchA, $owner] = $this->makeRestaurantWithOwner('Lifecycle Co');
        $branchB = \App\Models\Branch::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Second', 'branch_code' => 'SEC', 'status' => 'ACTIVE',
        ]);
        [$order, ] = $this->placeTakeawayOrder($restaurant, $branchB, $owner);

        $manager = $this->makeBranchScopedUser($restaurant, $branchA, 'branch-manager');
        $managerToken = $this->actingAsUser($manager);

        $this->withUserToken($managerToken)->getJson("/api/v1/orders/{$order['id']}")->assertStatus(403);
        $this->withUserToken($managerToken)->patchJson("/api/v1/orders/{$order['id']}/status", ['status' => 'CONFIRMED'])
            ->assertStatus(403);
    }
}
