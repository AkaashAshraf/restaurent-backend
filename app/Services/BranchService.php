<?php

namespace App\Services;

use App\Exceptions\BranchLimitExceededException;
use App\Models\Branch;
use App\Models\Restaurant;

/**
 * Centralizes branch creation rules — chiefly the subscription branch
 * limit (spec #8/#21) — so every entry point (admin panel, onboarding
 * flow, future imports) enforces it identically instead of re-checking
 * count() ad hoc.
 */
class BranchService
{
    public function assertCanCreateBranch(Restaurant $restaurant): void
    {
        $subscription = $restaurant->activeSubscription()->with('plan')->first();

        // No active subscription at all -> cannot add branches.
        if (! $subscription || ! $subscription->isCurrentlyActive()) {
            throw new BranchLimitExceededException('This restaurant has no active subscription.');
        }

        $limit = $subscription->branchLimit();

        if ($limit === null) {
            return; // unlimited
        }

        $currentCount = Branch::where('restaurant_id', $restaurant->id)->count();

        if ($currentCount >= $limit) {
            throw new BranchLimitExceededException(
                "This restaurant has reached its branch limit ({$limit})."
            );
        }
    }
}
