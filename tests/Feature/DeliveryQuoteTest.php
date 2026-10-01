<?php

namespace Tests\Feature;

use App\Models\DeliveryZone;
use Tests\TestCase;

/**
 * The public delivery quote the customer app uses: same zone + fee rules a
 * DELIVERY order is placed under, answered before the customer signs in.
 */
class DeliveryQuoteTest extends TestCase
{
    private function setup_restaurant(array $settings = []): array
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Quote Co', planSlug: 'premium');
        $restaurant->settings->update($settings + [
            'order_types' => ['DINE_IN', 'TAKEAWAY', 'DELIVERY'],
            'delivery_enabled' => true,
            'delivery_fee' => 150,
        ]);

        return [$restaurant, $branch, $owner];
    }

    private function quote($restaurant, $branch, array $query = [])
    {
        return $this->getJson('/api/v1/app/delivery-quote?'.http_build_query($query + [
            'restaurant' => $restaurant->slug, 'branch_id' => $branch->id,
        ]));
    }

    public function test_a_branch_without_zones_delivers_anywhere_at_the_default_fee(): void
    {
        [$restaurant, $branch] = $this->setup_restaurant();

        $this->quote($restaurant, $branch)->assertOk()
            ->assertJsonPath('data.deliverable', true)
            ->assertJsonPath('data.zones_configured', false)
            ->assertJsonPath('data.fee', 150);
    }

    public function test_inside_a_zone_uses_the_zone_fee_override(): void
    {
        [$restaurant, $branch] = $this->setup_restaurant();
        DeliveryZone::create([
            'restaurant_id' => $restaurant->id, 'branch_id' => $branch->id, 'name' => 'Clifton', 'type' => 'RADIUS',
            'center_latitude' => 24.8607, 'center_longitude' => 67.0011, 'radius_km' => 5, 'delivery_fee_override' => 80,
        ]);

        $this->quote($restaurant, $branch, ['latitude' => 24.86, 'longitude' => 67.0])->assertOk()
            ->assertJsonPath('data.deliverable', true)
            ->assertJsonPath('data.zone.name', 'Clifton')
            ->assertJsonPath('data.fee', 80);
    }

    public function test_outside_every_zone_is_not_deliverable(): void
    {
        [$restaurant, $branch] = $this->setup_restaurant();
        DeliveryZone::create([
            'restaurant_id' => $restaurant->id, 'branch_id' => $branch->id, 'name' => 'Clifton', 'type' => 'RADIUS',
            'center_latitude' => 24.8607, 'center_longitude' => 67.0011, 'radius_km' => 2,
        ]);

        $this->quote($restaurant, $branch, ['latitude' => 31.5, 'longitude' => 74.3])->assertOk()
            ->assertJsonPath('data.deliverable', false)
            ->assertJsonPath('data.reason', 'OUTSIDE_DELIVERY_AREA')
            ->assertJsonPath('data.fee', null);
    }

    public function test_zones_require_a_location_and_a_big_order_delivers_free(): void
    {
        [$restaurant, $branch] = $this->setup_restaurant(['free_delivery_threshold' => 2000]);
        DeliveryZone::create([
            'restaurant_id' => $restaurant->id, 'branch_id' => $branch->id, 'name' => 'Clifton', 'type' => 'RADIUS',
            'center_latitude' => 24.8607, 'center_longitude' => 67.0011, 'radius_km' => 5, 'delivery_fee_override' => 80,
        ]);

        $this->quote($restaurant, $branch)->assertOk()
            ->assertJsonPath('data.deliverable', false)
            ->assertJsonPath('data.reason', 'LOCATION_REQUIRED');

        $this->quote($restaurant, $branch, ['latitude' => 24.86, 'longitude' => 67.0, 'subtotal' => 2500])->assertOk()
            ->assertJsonPath('data.fee', 0);
    }

    public function test_an_unknown_branch_or_restaurant_is_not_found(): void
    {
        [$restaurant, $branch] = $this->setup_restaurant();

        $this->getJson('/api/v1/app/delivery-quote?restaurant='.$restaurant->slug.'&branch_id=999999')->assertStatus(404);
        $this->getJson('/api/v1/app/delivery-quote?restaurant=nope&branch_id='.$branch->id)->assertStatus(404);
    }

    public function test_public_config_flags_branches_that_use_delivery_zones(): void
    {
        [$restaurant, $branch] = $this->setup_restaurant();
        $this->getJson('/api/v1/app/config?restaurant='.$restaurant->slug)->assertOk()
            ->assertJsonPath('data.branches.0.has_delivery_zones', false);

        DeliveryZone::create([
            'restaurant_id' => $restaurant->id, 'branch_id' => $branch->id, 'name' => 'Z', 'type' => 'RADIUS',
            'center_latitude' => 24.86, 'center_longitude' => 67.0, 'radius_km' => 3,
        ]);
        $this->getJson('/api/v1/app/config?restaurant='.$restaurant->slug)->assertOk()
            ->assertJsonPath('data.branches.0.has_delivery_zones', true);
    }
}
