<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DeliveryZone;
use App\Models\Product;
use Tests\TestCase;

/**
 * Phase 5: the effect delivery zones have on DELIVERY order creation.
 * A branch with no active zones behaves exactly like Phase 3 (any
 * address accepted, no coordinates required); once a branch has at
 * least one active zone, coordinates are required and must actually
 * land inside one.
 */
class DeliveryGeofencingTest extends TestCase
{
    private function makeProduct($restaurant, float $price = 10.00): Product
    {
        $category = Category::create(['restaurant_id' => $restaurant->id, 'name' => 'Pizzas', 'slug' => 'pizzas-'.uniqid()]);

        return Product::create([
            'restaurant_id' => $restaurant->id, 'category_id' => $category->id,
            'name' => 'Margherita', 'slug' => 'margherita-'.uniqid(), 'base_price' => $price,
        ]);
    }

    private function makeDeliveryReadyRestaurant(string $name = 'Geo Co'): array
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner($name, planSlug: 'premium');
        $restaurant->settings->update(['order_types' => ['DINE_IN', 'TAKEAWAY', 'DELIVERY']]);

        return [$restaurant, $branch, $owner];
    }

    public function test_delivery_order_without_any_zones_configured_needs_no_coordinates(): void
    {
        [$restaurant, $branch, $owner] = $this->makeDeliveryReadyRestaurant();
        $product = $this->makeProduct($restaurant);
        $token = $this->actingAsUser($owner);

        $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'DELIVERY', 'delivery_address' => '1 Main St',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(201)->assertJsonPath('data.delivery_zone_id', null);
    }

    public function test_delivery_order_requires_coordinates_once_the_branch_has_a_zone(): void
    {
        [$restaurant, $branch, $owner] = $this->makeDeliveryReadyRestaurant();
        $product = $this->makeProduct($restaurant);
        $token = $this->actingAsUser($owner);

        DeliveryZone::create([
            'restaurant_id' => $restaurant->id, 'branch_id' => $branch->id, 'name' => 'Inner', 'type' => 'RADIUS',
            'center_latitude' => 24.8607, 'center_longitude' => 67.0011, 'radius_km' => 5,
        ]);

        $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'DELIVERY', 'delivery_address' => '1 Main St',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(422);
    }

    public function test_delivery_order_inside_a_radius_zone_is_accepted_and_records_the_zone(): void
    {
        [$restaurant, $branch, $owner] = $this->makeDeliveryReadyRestaurant();
        $product = $this->makeProduct($restaurant);
        $token = $this->actingAsUser($owner);

        $zone = DeliveryZone::create([
            'restaurant_id' => $restaurant->id, 'branch_id' => $branch->id, 'name' => 'Inner', 'type' => 'RADIUS',
            'center_latitude' => 24.8607, 'center_longitude' => 67.0011, 'radius_km' => 5,
        ]);

        // ~1km from the zone's center — well inside a 5km radius.
        $response = $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'DELIVERY', 'delivery_address' => '1 Main St',
            'delivery_latitude' => 24.8700, 'delivery_longitude' => 67.0011,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(201);

        $this->assertSame($zone->id, $response->json('data.delivery_zone_id'));
    }

    public function test_delivery_order_outside_every_zone_is_rejected(): void
    {
        [$restaurant, $branch, $owner] = $this->makeDeliveryReadyRestaurant();
        $product = $this->makeProduct($restaurant);
        $token = $this->actingAsUser($owner);

        DeliveryZone::create([
            'restaurant_id' => $restaurant->id, 'branch_id' => $branch->id, 'name' => 'Inner', 'type' => 'RADIUS',
            'center_latitude' => 24.8607, 'center_longitude' => 67.0011, 'radius_km' => 5,
        ]);

        // Roughly 200km+ away — well outside a 5km radius.
        $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'DELIVERY', 'delivery_address' => 'Far away',
            'delivery_latitude' => 26.8607, 'delivery_longitude' => 67.0011,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(422);
    }

    public function test_delivery_order_inside_a_polygon_zone_is_accepted(): void
    {
        [$restaurant, $branch, $owner] = $this->makeDeliveryReadyRestaurant();
        $product = $this->makeProduct($restaurant);
        $token = $this->actingAsUser($owner);

        DeliveryZone::create([
            'restaurant_id' => $restaurant->id, 'branch_id' => $branch->id, 'name' => 'Downtown', 'type' => 'POLYGON',
            'polygon' => [
                ['lat' => 24.80, 'lng' => 66.95],
                ['lat' => 24.80, 'lng' => 67.05],
                ['lat' => 24.90, 'lng' => 67.05],
                ['lat' => 24.90, 'lng' => 66.95],
            ],
        ]);

        // (24.85, 67.00) is inside that box.
        $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'DELIVERY', 'delivery_address' => '1 Main St',
            'delivery_latitude' => 24.85, 'delivery_longitude' => 67.00,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(201);

        // (25.50, 67.00) is well outside that box.
        $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'DELIVERY', 'delivery_address' => 'Far away',
            'delivery_latitude' => 25.50, 'delivery_longitude' => 67.00,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(422);
    }

    public function test_zone_delivery_fee_override_beats_the_branch_default_but_not_the_free_threshold(): void
    {
        [$restaurant, $branch, $owner] = $this->makeDeliveryReadyRestaurant();
        $restaurant->settings->update(['delivery_fee' => 5.00, 'free_delivery_threshold' => 100.00]);
        $product = $this->makeProduct($restaurant, 10.00);
        $token = $this->actingAsUser($owner);

        DeliveryZone::create([
            'restaurant_id' => $restaurant->id, 'branch_id' => $branch->id, 'name' => 'Inner', 'type' => 'RADIUS',
            'center_latitude' => 24.8607, 'center_longitude' => 67.0011, 'radius_km' => 5,
            'delivery_fee_override' => 2.50,
        ]);

        // Below the free threshold -> the zone's override fee applies, not
        // the branch's default 5.00.
        $small = $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'DELIVERY', 'delivery_address' => '1 Main St',
            'delivery_latitude' => 24.8700, 'delivery_longitude' => 67.0011,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(201);
        $this->assertEquals(2.5, $small->json('data.delivery_fee'));

        // Above the free threshold -> free delivery wins regardless of the
        // zone's override.
        $big = $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'DELIVERY', 'delivery_address' => '1 Main St',
            'delivery_latitude' => 24.8700, 'delivery_longitude' => 67.0011,
            'items' => [['product_id' => $product->id, 'quantity' => 11]],
        ])->assertStatus(201);
        $this->assertEquals(0.0, $big->json('data.delivery_fee'));
    }

    public function test_an_inactive_zone_is_never_matched(): void
    {
        [$restaurant, $branch, $owner] = $this->makeDeliveryReadyRestaurant();
        $product = $this->makeProduct($restaurant);
        $token = $this->actingAsUser($owner);

        DeliveryZone::create([
            'restaurant_id' => $restaurant->id, 'branch_id' => $branch->id, 'name' => 'Inner', 'type' => 'RADIUS',
            'center_latitude' => 24.8607, 'center_longitude' => 67.0011, 'radius_km' => 5,
            'is_active' => false,
        ]);

        // Inactive zone means the branch has no *active* zones -> falls
        // back to Phase 3 behavior (coordinates not required at all).
        $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'DELIVERY', 'delivery_address' => '1 Main St',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(201)->assertJsonPath('data.delivery_zone_id', null);
    }

    public function test_zones_from_another_branch_never_apply(): void
    {
        [$restaurant, $branchA, $owner] = $this->makeDeliveryReadyRestaurant();
        $branchB = \App\Models\Branch::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Second', 'branch_code' => 'SEC', 'status' => 'ACTIVE',
        ]);
        $product = $this->makeProduct($restaurant);
        $token = $this->actingAsUser($owner);

        // Zone exists only on branch B; branch A has none configured, so
        // branch A stays open to any address (Phase 3 fallback behavior).
        DeliveryZone::create([
            'restaurant_id' => $restaurant->id, 'branch_id' => $branchB->id, 'name' => 'Inner', 'type' => 'RADIUS',
            'center_latitude' => 24.8607, 'center_longitude' => 67.0011, 'radius_km' => 5,
        ]);

        $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branchA->id, 'order_type' => 'DELIVERY', 'delivery_address' => '1 Main St',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(201)->assertJsonPath('data.delivery_zone_id', null);
    }
}
