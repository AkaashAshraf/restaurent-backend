<?php

namespace App\Http\Middleware;

use App\Exceptions\FeatureDisabledException;
use App\Services\FeatureService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware parameterized with a feature key, e.g.
 *   Route::middleware('feature:RIDER_APP')->group(...)
 *
 * This is the backend enforcement half of spec #9 — hiding a nav item in
 * a frontend is never sufficient on its own.
 */
class EnsureFeatureEnabled
{
    public function __construct(private FeatureService $features)
    {
    }

    public function handle(Request $request, Closure $next, string $featureKey): Response
    {
        $user = $request->user();
        $restaurant = $user?->restaurant;

        // Super Admin has no restaurant context; feature checks don't apply.
        if ($restaurant === null) {
            return $next($request);
        }

        if (! $this->features->isEnabled($restaurant, $featureKey)) {
            throw new FeatureDisabledException($featureKey);
        }

        return $next($request);
    }
}
