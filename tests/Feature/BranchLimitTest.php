<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Spec #8/#21 — branch creation must be blocked once the plan's limit is hit. */
class BranchLimitTest extends TestCase
{
    public function test_cannot_create_branch_beyond_plan_branch_limit(): void
    {
        // Basic plan = 1 branch, and makeRestaurantWithOwner already creates one.
        [$restaurant, , $owner] = $this->makeRestaurantWithOwner('Limited Co', planSlug: 'basic');
        $token = $this->actingAsUser($owner);

        $response = $this->withUserToken($token)->postJson('/api/v1/branches', [
            'name' => 'Second Branch', 'branch_code' => 'B2',
        ]);

        $response->assertStatus(422);
        $response->assertJson(['code' => 'VALIDATION_ERROR']);
    }

    public function test_premium_plan_has_unlimited_branches(): void
    {
        [$restaurant, , $owner] = $this->makeRestaurantWithOwner('Unlimited Co', planSlug: 'premium');
        $token = $this->actingAsUser($owner);

        for ($i = 0; $i < 5; $i++) {
            $response = $this->withUserToken($token)->postJson('/api/v1/branches', [
                'name' => "Branch {$i}", 'branch_code' => "B{$i}",
            ]);
            $response->assertStatus(201);
        }
    }

    public function test_cannot_create_branch_without_any_subscription(): void
    {
        [$restaurant, , $owner] = $this->makeRestaurantWithOwner('No Sub Co', planSlug: null);
        $token = $this->actingAsUser($owner);

        $response = $this->withUserToken($token)->postJson('/api/v1/branches', [
            'name' => 'Branch X', 'branch_code' => 'BX',
        ]);

        // subscription.active middleware runs before branches.create permission check.
        $response->assertStatus(403);
        $response->assertJson(['code' => 'SUBSCRIPTION_EXPIRED']);
    }
}
