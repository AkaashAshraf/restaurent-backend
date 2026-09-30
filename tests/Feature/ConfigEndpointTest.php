<?php

namespace Tests\Feature;

use Tests\TestCase;

class ConfigEndpointTest extends TestCase
{
    public function test_public_app_config_resolves_restaurant_by_slug_and_includes_branches_and_features(): void
    {
        [$restaurant, $branch, ] = $this->makeRestaurantWithOwner('Config Co', planSlug: 'standard');

        $response = $this->getJson("/api/v1/app/config?restaurant={$restaurant->slug}");

        $response->assertOk();
        $this->assertSame($restaurant->id, $response->json('data.restaurant.id'));

        $branchIds = collect($response->json('data.branches'))->pluck('id');
        $this->assertTrue($branchIds->contains($branch->id));

        $this->assertTrue($response->json('data.features.WAITER_APP'));
        $this->assertArrayNotHasKey('RIDER_APP', $response->json('data.features'));
    }

    public function test_public_app_config_for_unknown_restaurant_returns_not_found(): void
    {
        $response = $this->getJson('/api/v1/app/config?restaurant=does-not-exist');

        $response->assertStatus(404);
        $response->assertJson(['code' => 'NOT_FOUND']);
    }

    public function test_app_config_never_leaks_a_different_restaurants_branches(): void
    {
        [$restaurantA, $branchA, ] = $this->makeRestaurantWithOwner('Config A');
        [$restaurantB, $branchB, ] = $this->makeRestaurantWithOwner('Config B');

        $response = $this->getJson("/api/v1/app/config?restaurant={$restaurantA->slug}");

        $branchIds = collect($response->json('data.branches'))->pluck('id');
        $this->assertTrue($branchIds->contains($branchA->id));
        $this->assertFalse($branchIds->contains($branchB->id));
    }

    /**
     * Regression test for a real bug caught during development: TenantContext
     * is a container singleton, and this public endpoint has no `tenant`
     * middleware to reset it. A prior authenticated Super Admin request in
     * the same PHP process sets bypass=true; without an explicit reset,
     * that flag would leak into this request and TenantScope would skip
     * tenant filtering entirely, returning every restaurant's branches.
     * Harmless under classic php-fpm (fresh process per request) but a
     * real cross-tenant leak under Octane or any worker-reuse runtime.
     */
    public function test_app_config_does_not_leak_across_requests_after_a_super_admin_call(): void
    {
        [$restaurantA, $branchA, ] = $this->makeRestaurantWithOwner('Leak Check A');
        [$restaurantB, $branchB, ] = $this->makeRestaurantWithOwner('Leak Check B');

        $superAdmin = $this->makeSuperAdmin();
        $token = $this->actingAsUser($superAdmin);
        $this->withUserToken($token)->getJson('/api/v1/super-admin/dashboard')->assertOk();

        // Immediately after, in the same test/container, an unauthenticated
        // public config request for restaurant A must only ever see A's own
        // branch — never B's, and never both.
        $response = $this->getJson("/api/v1/app/config?restaurant={$restaurantA->slug}");

        $branchIds = collect($response->json('data.branches'))->pluck('id');
        $this->assertSame([$branchA->id], $branchIds->all());
        $this->assertFalse($branchIds->contains($branchB->id));
    }
}
