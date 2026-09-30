<?php

namespace Tests\Feature;

use App\Models\Table;
use Tests\TestCase;

class TableManagementTest extends TestCase
{
    public function test_owner_can_create_list_update_and_delete_a_table(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Table Co');
        $token = $this->actingAsUser($owner);

        $created = $this->withUserToken($token)->postJson('/api/v1/tables', [
            'branch_id' => $branch->id,
            'table_number' => 'T1',
            'capacity' => 4,
        ])->assertStatus(201)->json('data');

        $this->assertSame('AVAILABLE', $created['status']);

        $this->withUserToken($token)->getJson('/api/v1/tables')
            ->assertOk()->assertJsonCount(1, 'data');

        $this->withUserToken($token)->patchJson("/api/v1/tables/{$created['id']}", [
            'status' => 'UNAVAILABLE',
        ])->assertOk()->assertJsonPath('data.status', 'UNAVAILABLE');

        $this->withUserToken($token)->deleteJson("/api/v1/tables/{$created['id']}")->assertOk();
        $this->assertSoftDeleted('tables', ['id' => $created['id']]);
    }

    public function test_table_routes_require_the_table_management_feature(): void
    {
        // Basic plan does not include TABLE_MANAGEMENT.
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('No Tables Co', planSlug: 'basic');
        $token = $this->actingAsUser($owner);

        $response = $this->withUserToken($token)->postJson('/api/v1/tables', [
            'branch_id' => $branch->id, 'table_number' => 'T1',
        ]);

        $response->assertStatus(403);
        $response->assertJson(['code' => 'FEATURE_DISABLED']);
    }

    public function test_waiter_can_view_and_update_a_table_in_their_own_branch_but_not_create_one(): void
    {
        [$restaurant, $branch, ] = $this->makeRestaurantWithOwner('Table Co');
        $waiter = $this->makeBranchScopedUser($restaurant, $branch, 'waiter');
        $token = $this->actingAsUser($waiter);

        $table = Table::create(['restaurant_id' => $restaurant->id, 'branch_id' => $branch->id, 'table_number' => 'T1']);

        $this->withUserToken($token)->getJson("/api/v1/tables/{$table->id}")->assertOk();
        $this->withUserToken($token)->patchJson("/api/v1/tables/{$table->id}", ['status' => 'RESERVED'])->assertOk();

        $this->withUserToken($token)->postJson('/api/v1/tables', [
            'branch_id' => $branch->id, 'table_number' => 'T2',
        ])->assertStatus(403);
    }

    public function test_branch_scoped_user_cannot_manage_a_table_in_another_branch(): void
    {
        [$restaurant, $branchA, ] = $this->makeRestaurantWithOwner('Table Co');
        $branchB = \App\Models\Branch::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Second', 'branch_code' => 'SEC', 'status' => 'ACTIVE',
        ]);
        $tableB = Table::create(['restaurant_id' => $restaurant->id, 'branch_id' => $branchB->id, 'table_number' => 'T1']);

        $manager = $this->makeBranchScopedUser($restaurant, $branchA, 'branch-manager');
        $token = $this->actingAsUser($manager);

        $this->withUserToken($token)->getJson("/api/v1/tables/{$tableB->id}")->assertStatus(403);
        $this->withUserToken($token)->postJson('/api/v1/tables', [
            'branch_id' => $branchB->id, 'table_number' => 'T9',
        ])->assertStatus(403);
    }

    public function test_tables_are_isolated_per_restaurant(): void
    {
        [$restaurantA, $branchA, $ownerA] = $this->makeRestaurantWithOwner('Table A');
        [$restaurantB, $branchB, ] = $this->makeRestaurantWithOwner('Table B');

        $tableB = Table::create(['restaurant_id' => $restaurantB->id, 'branch_id' => $branchB->id, 'table_number' => 'T1']);

        $token = $this->actingAsUser($ownerA);
        $this->withUserToken($token)->getJson("/api/v1/tables/{$tableB->id}")->assertStatus(404);
    }
}
