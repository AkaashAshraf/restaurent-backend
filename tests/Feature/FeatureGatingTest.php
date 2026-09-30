<?php

namespace Tests\Feature;

use App\Models\Feature;
use App\Models\Restaurant;
use App\Services\FeatureService;
use Tests\TestCase;

/**
 * Spec #9/#139 — disabled features must be rejected by the backend, not
 * just hidden in a frontend. We don't have a RIDER_APP-gated endpoint in
 * Phase 1 yet, so this exercises the FeatureService + middleware directly
 * against a real restaurant/subscription, which is the mechanism every
 * future feature-gated route will rely on.
 */
class FeatureGatingTest extends TestCase
{
    public function test_basic_plan_restaurant_does_not_have_rider_app_enabled(): void
    {
        [$restaurant, , ] = $this->makeRestaurantWithOwner('Basic Co', planSlug: 'basic');

        $enabled = app(FeatureService::class)->isEnabled($restaurant, 'RIDER_APP');

        $this->assertFalse($enabled);
    }

    public function test_standard_plan_restaurant_has_waiter_app_enabled(): void
    {
        [$restaurant, , ] = $this->makeRestaurantWithOwner('Standard Co', planSlug: 'standard');

        $this->assertTrue(app(FeatureService::class)->isEnabled($restaurant, 'WAITER_APP'));
    }

    public function test_super_admin_restaurant_level_override_disables_a_plan_feature(): void
    {
        [$restaurant, , ] = $this->makeRestaurantWithOwner('Standard Co', planSlug: 'standard');
        $feature = Feature::where('key', 'WAITER_APP')->first();

        $this->assertTrue(app(FeatureService::class)->isEnabled($restaurant, 'WAITER_APP'));

        $superAdmin = $this->makeSuperAdmin();
        $token = $this->actingAsUser($superAdmin);

        $response = $this->withUserToken($token)
            ->patchJson("/api/v1/super-admin/restaurants/{$restaurant->id}/features/{$feature->id}", [
                'enabled' => false,
            ]);

        $response->assertOk();

        $this->assertFalse(app(FeatureService::class)->isEnabled($restaurant->fresh(), 'WAITER_APP'));
    }

    public function test_super_admin_restaurant_level_override_can_enable_a_feature_outside_the_plan(): void
    {
        [$restaurant, , ] = $this->makeRestaurantWithOwner('Basic Co', planSlug: 'basic');
        $feature = Feature::where('key', 'RIDER_APP')->first();

        $this->assertFalse(app(FeatureService::class)->isEnabled($restaurant, 'RIDER_APP'));

        $superAdmin = $this->makeSuperAdmin();
        $token = $this->actingAsUser($superAdmin);

        $this->withUserToken($token)
            ->patchJson("/api/v1/super-admin/restaurants/{$restaurant->id}/features/{$feature->id}", [
                'enabled' => true,
            ])->assertOk();

        $this->assertTrue(app(FeatureService::class)->isEnabled($restaurant->fresh(), 'RIDER_APP'));
    }

    public function test_restaurant_with_no_active_subscription_has_no_features_enabled(): void
    {
        [$restaurant, , ] = $this->makeRestaurantWithOwner('No Sub Co', planSlug: null);

        $this->assertFalse(app(FeatureService::class)->isEnabled($restaurant, 'ADMIN_PANEL'));
        $this->assertEmpty(app(FeatureService::class)->enabledFeatureKeys($restaurant));
    }

    public function test_non_super_admin_cannot_toggle_restaurant_feature_overrides(): void
    {
        [$restaurant, , $owner] = $this->makeRestaurantWithOwner('Standard Co', planSlug: 'standard');
        $feature = Feature::where('key', 'WAITER_APP')->first();

        $token = $this->actingAsUser($owner);

        $response = $this->withUserToken($token)
            ->patchJson("/api/v1/super-admin/restaurants/{$restaurant->id}/features/{$feature->id}", [
                'enabled' => false,
            ]);

        $response->assertStatus(403);
        $response->assertJson(['code' => 'FORBIDDEN']);
    }
}
