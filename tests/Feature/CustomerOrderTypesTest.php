<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\DeliveryZone;
use App\Models\Product;
use Tests\TestCase;

/**
 * The customer app only offers Delivery and/or Takeaway (the restaurant picks,
 * at least one), and can find the branch that actually serves an address.
 */
class CustomerOrderTypesTest extends TestCase
{
    private function setupRestaurant(): array
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Types Co', planSlug: 'premium');
        $restaurant->settings->update(['order_types' => ['DINE_IN', 'TAKEAWAY', 'DELIVERY'], 'delivery_fee' => 100]);

        return [$restaurant, $branch, $this->actingAsUser($owner)];
    }

    private function customerToken($restaurant): string
    {
        return $this->postJson("/api/v1/app/auth/register?restaurant={$restaurant->slug}", [
            'phone' => '5551234', 'password' => 'secret123',
        ])->assertStatus(201)->json('data.token');
    }

    private function product($restaurant): Product
    {
        $category = Category::create(['restaurant_id' => $restaurant->id, 'name' => 'Pizzas', 'slug' => 'pizzas-'.uniqid()]);

        return Product::create([
            'restaurant_id' => $restaurant->id, 'category_id' => $category->id,
            'name' => 'Margherita', 'slug' => 'margherita-'.uniqid(), 'base_price' => 10,
        ]);
    }

    public function test_the_app_offers_delivery_and_takeaway_never_dine_in(): void
    {
        [$restaurant] = $this->setupRestaurant();

        $this->getJson('/api/v1/app/config?restaurant='.$restaurant->slug)->assertOk()
            ->assertJsonPath('data.ordering.order_types', ['DELIVERY', 'TAKEAWAY'])
            ->assertJsonPath('data.ordering.delivery_enabled', true);
    }

    public function test_the_restaurant_chooses_which_and_needs_at_least_one(): void
    {
        [$restaurant, , $ownerToken] = $this->setupRestaurant();

        $this->withUserToken($ownerToken)->patchJson('/api/v1/settings/ordering', ['customer_order_types' => ['TAKEAWAY']])
            ->assertOk()->assertJsonPath('data.customer_order_types', ['TAKEAWAY']);
        $this->getJson('/api/v1/app/config?restaurant='.$restaurant->slug)
            ->assertJsonPath('data.ordering.order_types', ['TAKEAWAY'])
            ->assertJsonPath('data.ordering.delivery_enabled', false);

        $this->withUserToken($ownerToken)->patchJson('/api/v1/settings/ordering', ['customer_order_types' => []])->assertStatus(422);
        $this->withUserToken($ownerToken)->patchJson('/api/v1/settings/ordering', ['customer_order_types' => ['DINE_IN']])->assertStatus(422);
    }

    public function test_the_we_deliver_switch_keeps_the_app_in_step_and_never_leaves_none(): void
    {
        [$restaurant, , $ownerToken] = $this->setupRestaurant();

        $this->withUserToken($ownerToken)->patchJson('/api/v1/settings/ordering', ['delivery_enabled' => false])
            ->assertOk()->assertJsonPath('data.customer_order_types', ['TAKEAWAY']);

        // Takeaway is the only one left: switching delivery off again is not allowed...
        $this->withUserToken($ownerToken)->patchJson('/api/v1/settings/ordering', ['customer_order_types' => ['DELIVERY']])->assertOk();
        $this->withUserToken($ownerToken)->patchJson('/api/v1/settings/ordering', ['delivery_enabled' => false])->assertStatus(422);

        $this->withUserToken($ownerToken)->patchJson('/api/v1/settings/ordering', ['delivery_enabled' => true])
            ->assertOk()->assertJsonPath('data.customer_order_types', ['DELIVERY']);
    }

    public function test_a_customer_cannot_order_a_type_the_restaurant_switched_off(): void
    {
        [$restaurant, $branch, $ownerToken] = $this->setupRestaurant();
        $items = [['product_id' => $this->product($restaurant)->id, 'quantity' => 1]];
        $token = $this->customerToken($restaurant);

        $this->withUserToken($ownerToken)->patchJson('/api/v1/settings/ordering', ['customer_order_types' => ['TAKEAWAY']])->assertOk();

        $this->withUserToken($token)->postJson('/api/v1/customer/orders', [
            'branch_id' => $branch->id, 'order_type' => 'DELIVERY', 'items' => $items,
        ])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'does not take delivery'));

        // Dine-in is never available in the app, even though staff use it.
        $this->withUserToken($token)->postJson('/api/v1/customer/orders', [
            'branch_id' => $branch->id, 'order_type' => 'DINE_IN', 'items' => $items,
        ])->assertStatus(422);

        $this->withUserToken($token)->postJson('/api/v1/customer/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY', 'items' => $items,
        ])->assertStatus(201);
    }

    public function test_delivery_branches_finds_the_branch_whose_zone_covers_the_address(): void
    {
        [$restaurant, $north] = $this->setupRestaurant();
        $north->update(['name' => 'North', 'latitude' => 24.95, 'longitude' => 67.05, 'priority' => 1]);
        $south = Branch::create([
            'restaurant_id' => $restaurant->id, 'name' => 'South', 'branch_code' => 'S1', 'status' => 'ACTIVE',
            'priority' => 2, 'latitude' => 24.80, 'longitude' => 67.00,
        ]);
        foreach ([[$north, 24.95, 67.05], [$south, 24.80, 67.00]] as [$branch, $lat, $lng]) {
            DeliveryZone::create([
                'restaurant_id' => $restaurant->id, 'branch_id' => $branch->id, 'name' => $branch->name.' zone', 'type' => 'RADIUS',
                'center_latitude' => $lat, 'center_longitude' => $lng, 'radius_km' => 4, 'delivery_fee_override' => $branch->id === $south->id ? 70 : 90,
            ]);
        }

        // An address in the south: only South delivers there.
        $res = $this->getJson('/api/v1/app/delivery-branches?'.http_build_query([
            'restaurant' => $restaurant->slug, 'latitude' => 24.81, 'longitude' => 67.0,
        ]))->assertOk();
        $this->assertSame([$south->id], array_column($res->json('data.branches'), 'branch_id'));
        $res->assertJsonPath('data.branches.0.fee', 70)->assertJsonPath('data.branches.0.zone.name', 'South zone');

        // Nowhere near either.
        $this->getJson('/api/v1/app/delivery-branches?'.http_build_query([
            'restaurant' => $restaurant->slug, 'latitude' => 31.5, 'longitude' => 74.3,
        ]))->assertOk()->assertJsonCount(0, 'data.branches');

        // A location is required.
        $this->getJson('/api/v1/app/delivery-branches?restaurant='.$restaurant->slug)->assertStatus(422);
    }

    public function test_a_branch_without_zones_is_offered_after_zoned_matches(): void
    {
        [$restaurant, $zoned] = $this->setupRestaurant();
        $zoned->update(['latitude' => 24.95, 'longitude' => 67.05, 'priority' => 5]);
        DeliveryZone::create([
            'restaurant_id' => $restaurant->id, 'branch_id' => $zoned->id, 'name' => 'Z', 'type' => 'RADIUS',
            'center_latitude' => 24.95, 'center_longitude' => 67.05, 'radius_km' => 5,
        ]);
        $open = Branch::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Anywhere', 'branch_code' => 'A1', 'status' => 'ACTIVE',
            'priority' => 1, 'latitude' => 24.70, 'longitude' => 67.0,
        ]);

        $res = $this->getJson('/api/v1/app/delivery-branches?'.http_build_query([
            'restaurant' => $restaurant->slug, 'latitude' => 24.95, 'longitude' => 67.05,
        ]))->assertOk();

        $this->assertSame([$zoned->id, $open->id], array_column($res->json('data.branches'), 'branch_id'));
    }
}
