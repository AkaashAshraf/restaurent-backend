<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Spec #24/#139 — "Unauthorized users cannot perform restricted actions,"
 * enforced by the `permission` middleware reading the PermissionService,
 * not by the frontend hiding a button.
 */
class PermissionEnforcementTest extends TestCase
{
    public function test_branch_manager_cannot_create_a_branch(): void
    {
        [$restaurant, $branch, ] = $this->makeRestaurantWithOwner('Perm Co');
        $branchManager = $this->makeBranchScopedUser($restaurant, $branch, 'branch-manager');

        $token = $this->actingAsUser($branchManager);

        $response = $this->withUserToken($token)->postJson('/api/v1/branches', [
            'name' => 'New Branch', 'branch_code' => 'NB1',
        ]);

        $response->assertStatus(403);
        $response->assertJson(['code' => 'FORBIDDEN']);
    }

    public function test_owner_can_create_a_branch(): void
    {
        [$restaurant, , $owner] = $this->makeRestaurantWithOwner('Perm Co');
        $token = $this->actingAsUser($owner);

        $response = $this->withUserToken($token)->postJson('/api/v1/branches', [
            'name' => 'New Branch', 'branch_code' => 'NB1',
        ]);

        $response->assertStatus(201);
    }

    public function test_waiter_cannot_view_users_list(): void
    {
        [$restaurant, $branch, ] = $this->makeRestaurantWithOwner('Perm Co');
        $waiter = $this->makeBranchScopedUser($restaurant, $branch, 'waiter');
        $token = $this->actingAsUser($waiter);

        $response = $this->withUserToken($token)->getJson('/api/v1/users');

        $response->assertStatus(403);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/branches');

        $response->assertStatus(401);
        $response->assertJson(['code' => 'UNAUTHORIZED']);
    }

    public function test_super_admin_route_rejects_regular_restaurant_owner(): void
    {
        [, , $owner] = $this->makeRestaurantWithOwner('Perm Co');
        $token = $this->actingAsUser($owner);

        $response = $this->withUserToken($token)->getJson('/api/v1/super-admin/dashboard');

        $response->assertStatus(403);
        $response->assertJson(['code' => 'FORBIDDEN']);
    }
}
