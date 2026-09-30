<?php

namespace App\Services;

use App\Models\Restaurant;
use Illuminate\Support\Facades\Cache;

/**
 * Single source of truth for "is feature X enabled for restaurant Y".
 *
 * Resolution order:
 *   1. Restaurant-level override (restaurant_features) — Super Admin can
 *      grant/revoke a feature for one restaurant regardless of its plan.
 *   2. Subscription-level override (subscription_features) — seeded from
 *      the plan when the subscription was created, editable afterwards.
 *   3. Plan default (subscription_plan_features).
 *   4. No active subscription at all -> everything disabled.
 *
 * Both the frontend (to hide UI) and the backend (via the `feature`
 * middleware) must call this same service — hiding a menu item is not
 * enough on its own (spec #9).
 */
class FeatureService
{
    private const CACHE_TTL = 60; // seconds

    public function isEnabled(Restaurant $restaurant, string $featureKey): bool
    {
        return in_array($featureKey, $this->enabledFeatureKeys($restaurant), true);
    }

    public function enabledFeatureKeys(Restaurant $restaurant): array
    {
        return Cache::remember(
            "restaurant:{$restaurant->id}:features",
            self::CACHE_TTL,
            fn () => $this->resolveEnabledFeatureKeys($restaurant)
        );
    }

    public function forget(Restaurant $restaurant): void
    {
        Cache::forget("restaurant:{$restaurant->id}:features");
    }

    private function resolveEnabledFeatureKeys(Restaurant $restaurant): array
    {
        $subscription = $restaurant->activeSubscription()->with('plan.features', 'featureOverrides.feature')->first();

        if (! $subscription || ! $subscription->isCurrentlyActive()) {
            return [];
        }

        // Start from plan defaults.
        $enabled = $subscription->plan->features->pluck('key')->flip()->map(fn () => true)->all();

        // Apply subscription-level overrides.
        foreach ($subscription->featureOverrides as $override) {
            $enabled[$override->feature->key] = $override->enabled;
        }

        // Apply restaurant-level overrides (highest precedence, spec #10).
        $restaurantOverrides = $restaurant->featureOverrides()->with('feature')->get();
        foreach ($restaurantOverrides as $override) {
            $enabled[$override->feature->key] = $override->enabled;
        }

        return array_keys(array_filter($enabled));
    }
}
