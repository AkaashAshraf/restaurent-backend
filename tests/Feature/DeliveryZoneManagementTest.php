<?php

namespace Tests\Feature;

use App\Models\Branch;
use Tests\TestCase;

/**
 * CRUD + gating for delivery zones themselves (Phase 5) — the geofencing
 * *effect* on order creation is covered separately in
 * DeliveryGeofencingTest. Follows the same branch-scoping/tenant-isolation
 * conventions already proven for tables (TableManagementTest).
 */
class DeliveryZoneManagementTest extends TestCase
{
    private function radiusPayload(int $branchId): array
    {
        return [
            'branch_id' => $branchId,
            'name' => 'Inner city',
            'type' => 'RADIUS',
            'center_latitude' => 24.8607,
            'center_longitude' => 67.0011,
            'radius_km' => 5,
        ];
    }

    public function test_owner_can_create_list_update_and_delete_a_delivery_zone(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Zone Co', planSlug: 'premium');
        $token = $this->actingAsUser($owner);

        $created = $this->withUserToken($token)->postJson('/api/v1/delivery-zones', $this->radiusPayload($branch->id))
            ->assertStatus(201)->json('data');

        $this->withUserToken($token)->getJson('/api/v1/delivery-zones')
            ->assertOk()->assertJsonCount(1, 'data');

        $updated = $this->withUserToken($token)->patchJson("/api/v1/delivery-zones/{$created['id']}", ['radius_km' => 8])
            ->assertOk()->json('data');
        $this->assertEquals(8, $updated['radius_km']);

        $this->withUserToken($token)->deleteJson("/api/v1/delivery-zones/{$created['id']}")->assertOk();
        $this->withUserToken($token)->getJson('/api/v1/delivery-zones')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_polygon_zone_requires_at_least_three_points(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Zone Co', planSlug: 'premium');
        $token = $this->actingAsUser($owner);

        $this->withUserToken($token)->postJson('/api/v1/delivery-zones', [
            'branch_id' => $branch->id, 'name' => 'Downtown', 'type' => 'POLYGON',
            'polygon' => [['lat' => 24.85, 'lng' => 67.00], ['lat' => 24.87, 'lng' => 67.02]],
        ])->assertStatus(422);

        $this->withUserToken($token)->postJson('/api/v1/delivery-zones', [
            'branch_id' => $branch->id, 'name' => 'Downtown', 'type' => 'POLYGON',
            'polygon' => [
                ['lat' => 24.85, 'lng' => 67.00], ['lat' => 24.87, 'lng' => 67.02], ['lat' => 24.85, 'lng' => 67.02],
            ],
        ])->assertStatus(201);
    }

    public function test_delivery_zone_routes_require_the_delivery_feature(): void
    {
        // Standard plan does not include DELIVERY.
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Zone Co', planSlug: 'standard');
        $token = $this->actingAsUser($owner);

        $this->withUserToken($token)->postJson('/api/v1/delivery-zones', $this->radiusPayload($branch->id))
            ->assertStatus(403)->assertJson(['code' => 'FEATURE_DISABLED']);
    }

    public function test_branch_manager_can_manage_zones_for_their_own_branch_but_not_delete(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Zone Co', planSlug: 'premium');
        $manager = $this->makeBranchScopedUser($restaurant, $branch, 'branch-manager');
        $token = $this->actingAsUser($manager);

        $created = $this->withUserToken($token)->postJson('/api/v1/delivery-zones', $this->radiusPayload($branch->id))
            ->assertStatus(201)->json('data');

        $this->withUserToken($token)->patchJson("/api/v1/delivery-zones/{$created['id']}", ['radius_km' => 3])->assertOk();

        // branch-manager was not granted delivery-zones.delete.
        $this->withUserToken($token)->deleteJson("/api/v1/delivery-zones/{$created['id']}")->assertStatus(403);
    }

    public function test_branch_scoped_user_cannot_create_a_zone_for_another_branch(): void
    {
        [$restaurant, $branchA, $owner] = $this->makeRestaurantWithOwner('Zone Co', planSlug: 'premium');
        $branchB = Branch::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Second', 'branch_code' => 'SEC', 'status' => 'ACTIVE',
        ]);
        $manager = $this->makeBranchScopedUser($restaurant, $branchA, 'branch-manager');
        $token = $this->actingAsUser($manager);

        $this->withUserToken($token)->postJson('/api/v1/delivery-zones', $this->radiusPayload($branchB->id))
            ->assertStatus(403);
    }

    public function test_delivery_zones_are_isolated_per_restaurant(): void
    {
        [$restaurantA, $branchA, $ownerA] = $this->makeRestaurantWithOwner('Zone A', planSlug: 'premium');
        [$restaurantB, $branchB, $ownerB] = $this->makeRestaurantWithOwner('Zone B', planSlug: 'premium');

        $tokenB = $this->actingAsUser($ownerB);
        $zoneB = $this->withUserToken($tokenB)->postJson('/api/v1/delivery-zones', $this->radiusPayload($branchB->id))
            ->assertStatus(201)->json('data');

        $tokenA = $this->actingAsUser($ownerA);
        $this->withUserToken($tokenA)->getJson("/api/v1/delivery-zones/{$zoneB['id']}")->assertStatus(404);
        $this->withUserToken($tokenA)->getJson('/api/v1/delivery-zones')->assertOk()->assertJsonCount(0, 'data');
    }
}
