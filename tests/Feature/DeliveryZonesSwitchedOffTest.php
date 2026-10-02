<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DeliveryZone;
use App\Models\Product;
use Tests\TestCase;

/**
 * Delivery zones can be switched off (config/delivery.php): the zone data stays,
 * but nothing enforces it, so anyone can order delivery from anywhere.
 */
class DeliveryZonesSwitchedOffTest extends TestCase
{
    private function restaurantWithAZone(): array
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Off Co', planSlug: 'premium');
        $restaurant->settings->update(['order_types' => ['DINE_IN', 'TAKEAWAY', 'DELIVERY'], 'delivery_enabled' => true, 'delivery_fee' => 150]);
        DeliveryZone::create([
            'restaurant_id' => $restaurant->id, 'branch_id' => $branch->id, 'name' => 'Clifton', 'type' => 'RADIUS',
            'center_latitude' => 24.8607, 'center_longitude' => 67.0011, 'radius_km' => 5, 'delivery_fee_override' => 80,
        ]);

        return [$restaurant, $branch, $owner];
    }

    public function test_the_quote_is_deliverable_outside_the_zone_at_the_default_fee(): void
    {
        config(['delivery.zones_enabled' => false]);
        [$restaurant, $branch] = $this->restaurantWithAZone();

        // ~100 km from the zone.
        $this->getJson('/api/v1/app/delivery-quote?'.http_build_query([
            'restaurant' => $restaurant->slug, 'branch_id' => $branch->id, 'latitude' => 25.8, 'longitude' => 68.0,
        ]))->assertOk()
            ->assertJsonPath('data.deliverable', true)
            ->assertJsonPath('data.zones_configured', false)
            ->assertJsonPath('data.fee', 150);
    }

    public function test_the_same_quote_is_refused_when_zones_are_on(): void
    {
        config(['delivery.zones_enabled' => true]);
        [$restaurant, $branch] = $this->restaurantWithAZone();

        $this->getJson('/api/v1/app/delivery-quote?'.http_build_query([
            'restaurant' => $restaurant->slug, 'branch_id' => $branch->id, 'latitude' => 25.8, 'longitude' => 68.0,
        ]))->assertOk()->assertJsonPath('data.deliverable', false);
    }

    public function test_a_delivery_order_outside_the_zone_is_accepted(): void
    {
        config(['delivery.zones_enabled' => false]);
        [$restaurant, $branch, $owner] = $this->restaurantWithAZone();
        $category = Category::create(['restaurant_id' => $restaurant->id, 'name' => 'Pizzas', 'slug' => 'pizzas-'.uniqid()]);
        $product = Product::create([
            'restaurant_id' => $restaurant->id, 'category_id' => $category->id,
            'name' => 'Margherita', 'slug' => 'margherita-'.uniqid(), 'base_price' => 10,
        ]);
        $token = $this->actingAsUser($owner);

        $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'DELIVERY', 'delivery_address' => 'Far away',
            'delivery_latitude' => 25.8, 'delivery_longitude' => 68.0,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(201)->assertJsonPath('data.delivery_zone_id', null);
    }

    public function test_the_app_config_and_settings_report_zones_as_off(): void
    {
        config(['delivery.zones_enabled' => false]);
        [$restaurant, $branch, $owner] = $this->restaurantWithAZone();

        $this->getJson('/api/v1/app/config?restaurant='.$restaurant->slug)->assertOk()
            ->assertJsonPath('data.branches.0.has_delivery_zones', false);

        $this->withUserToken($this->actingAsUser($owner))->getJson('/api/v1/settings')->assertOk()
            ->assertJsonPath('data.delivery_zones_enabled', false);
    }
}
