<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\DeliveryZone;

/**
 * Single source of truth for "does this branch have delivery zones
 * configured, and if so, which one (if any) covers this point." Kept
 * separate from OrderService the same way FeatureService/PermissionService
 * are — one focused concern, reusable outside order creation later (e.g.
 * a future "can we deliver here at all" pre-checkout lookup).
 */
class GeofencingService
{
    /** Zones can be switched off for the whole platform (config/delivery.php). */
    public function enabled(): bool
    {
        return (bool) config('delivery.zones_enabled', false);
    }

    public function branchHasZonesConfigured(Branch $branch): bool
    {
        // Switched off: every branch behaves as "no zones set up" — it delivers anywhere.
        if (! $this->enabled()) {
            return false;
        }

        return DeliveryZone::where('branch_id', $branch->id)->where('is_active', true)->exists();
    }

    /**
     * The first active zone (of this branch) whose shape contains the
     * point, or null if none does. Zones aren't expected to overlap in
     * practice, but if they did, first-match-wins by creation order.
     */
    public function resolveZone(Branch $branch, float $latitude, float $longitude): ?DeliveryZone
    {
        return DeliveryZone::where('branch_id', $branch->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->first(fn (DeliveryZone $zone) => $zone->containsPoint($latitude, $longitude));
    }
}
