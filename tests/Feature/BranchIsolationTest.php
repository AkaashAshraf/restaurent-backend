<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Spec #22/#139 — "Branch A staff cannot access Branch B unless authorized."
 */
class BranchIsolationTest extends TestCase
{
    public function test_branch_scoped_user_cannot_view_a_different_branch_in_the_same_restaurant(): void
    {
        [$restaurant, $branchA, ] = $this->makeRestaurantWithOwner('Multi Branch Co');

        $branchB = \App\Models\Branch::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Branch B',
            'branch_code' => 'B2',
            'status' => 'ACTIVE',
        ]);

        $branchUser = $this->makeBranchScopedUser($restaurant, $branchA);
        $token = $this->actingAsUser($branchUser);

        $response = $this->withUserToken($token)
            ->getJson("/api/v1/branches/{$branchB->id}");

        $response->assertStatus(403);
        $response->assertJson(['code' => 'FORBIDDEN']);
    }

    public function test_branch_scoped_user_can_view_their_own_assigned_branch(): void
    {
        [$restaurant, $branchA, ] = $this->makeRestaurantWithOwner('Multi Branch Co');
        $branchUser = $this->makeBranchScopedUser($restaurant, $branchA);
        $token = $this->actingAsUser($branchUser);

        $response = $this->withUserToken($token)
            ->getJson("/api/v1/branches/{$branchA->id}");

        $response->assertOk();
        $this->assertSame($branchA->id, $response->json('data.id'));
    }

    public function test_branch_listing_for_branch_scoped_user_is_filtered_to_assigned_branches(): void
    {
        [$restaurant, $branchA, ] = $this->makeRestaurantWithOwner('Multi Branch Co');
        $branchB = \App\Models\Branch::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Branch B',
            'branch_code' => 'B2',
            'status' => 'ACTIVE',
        ]);

        $branchUser = $this->makeBranchScopedUser($restaurant, $branchA);
        $token = $this->actingAsUser($branchUser);

        $response = $this->withUserToken($token)->getJson('/api/v1/branches');
        $ids = collect($response->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($branchA->id));
        $this->assertFalse($ids->contains($branchB->id));
    }

    public function test_restaurant_wide_role_sees_all_branches(): void
    {
        [$restaurant, $branchA, $owner] = $this->makeRestaurantWithOwner('Multi Branch Co');
        \App\Models\Branch::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Branch B', 'branch_code' => 'B2', 'status' => 'ACTIVE',
        ]);

        $token = $this->actingAsUser($owner);
        $response = $this->withUserToken($token)->getJson('/api/v1/branches');

        $this->assertCount(2, $response->json('data'));
    }
}
