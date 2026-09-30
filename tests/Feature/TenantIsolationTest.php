<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Spec #139 — "Restaurant A cannot access Restaurant B [data]." This is
 * the single most important security property of the whole platform.
 */
class TenantIsolationTest extends TestCase
{
    public function test_restaurant_admin_cannot_view_another_restaurants_branch(): void
    {
        [$restaurantA, $branchA, $ownerA] = $this->makeRestaurantWithOwner('Restaurant A');
        [$restaurantB, $branchB, $ownerB] = $this->makeRestaurantWithOwner('Restaurant B');

        $token = $this->actingAsUser($ownerA);

        // Restaurant A owner trying to read Restaurant B's branch by id.
        $response = $this->withUserToken($token)
            ->getJson("/api/v1/branches/{$branchB->id}");

        $response->assertStatus(404);
    }

    public function test_restaurant_admin_branch_listing_never_includes_other_restaurants_branches(): void
    {
        [$restaurantA, $branchA, $ownerA] = $this->makeRestaurantWithOwner('Restaurant A');
        [$restaurantB, $branchB, $ownerB] = $this->makeRestaurantWithOwner('Restaurant B');

        $token = $this->actingAsUser($ownerA);

        $response = $this->withUserToken($token)->getJson('/api/v1/branches');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($branchA->id));
        $this->assertFalse($ids->contains($branchB->id));
    }

    public function test_restaurant_admin_cannot_view_another_restaurants_users(): void
    {
        [$restaurantA, , $ownerA] = $this->makeRestaurantWithOwner('Restaurant A');
        [$restaurantB, , $ownerB] = $this->makeRestaurantWithOwner('Restaurant B');

        $token = $this->actingAsUser($ownerA);

        $response = $this->withUserToken($token)
            ->getJson("/api/v1/users/{$ownerB->id}");

        $response->assertStatus(404);
    }

    public function test_creating_a_branch_always_attaches_the_current_tenant_even_if_forged(): void
    {
        [$restaurantA, , $ownerA] = $this->makeRestaurantWithOwner('Restaurant A');
        [$restaurantB, , ] = $this->makeRestaurantWithOwner('Restaurant B');

        $token = $this->actingAsUser($ownerA);

        // Attempt to smuggle a different restaurant_id in the payload — the
        // controller only ever writes the authenticated tenant's id, and
        // BelongsToTenant would auto-fill it even if the field were mass
        // assignable, so this must land under Restaurant A regardless.
        $response = $this->withUserToken($token)->postJson('/api/v1/branches', [
            'name' => 'Sneaky Branch',
            'branch_code' => 'SNEAK',
            'restaurant_id' => $restaurantB->id,
        ]);

        $response->assertStatus(201);
        $this->assertSame($restaurantA->id, $response->json('data.restaurant_id'));
    }

    /**
     * Regression test: TenantContext is a container singleton set by the
     * `tenant` middleware. IdentifyTenant must fully reset it on every
     * request (not just overwrite the fields relevant to the current
     * user) — otherwise a Super Admin request's bypass=true could leak
     * into the very next authenticated restaurant-scoped request under a
     * worker-reuse runtime, and that request would see every restaurant's
     * data instead of just its own.
     */
    public function test_bypass_from_a_super_admin_request_does_not_leak_into_the_next_restaurant_request(): void
    {
        [$restaurantA, $branchA, $ownerA] = $this->makeRestaurantWithOwner('Restaurant A');
        [$restaurantB, $branchB, ] = $this->makeRestaurantWithOwner('Restaurant B');

        $superAdmin = $this->makeSuperAdmin();
        $superToken = $this->actingAsUser($superAdmin);
        $this->withUserToken($superToken)->getJson('/api/v1/super-admin/dashboard')->assertOk();

        $ownerToken = $this->actingAsUser($ownerA);
        $response = $this->withUserToken($ownerToken)->getJson('/api/v1/branches');

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertSame([$branchA->id], $ids->all());
        $this->assertFalse($ids->contains($branchB->id));
    }
}
