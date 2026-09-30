<?php

namespace App\Services;

use App\Models\Restaurant;
use App\Models\RestaurantDomain;
use Illuminate\Http\Request;

/**
 * The one place "which restaurant is this public/pre-login request for"
 * gets answered: the `Host` header against `restaurant_domains` first
 * (a white-labeled custom domain), falling back to an explicit
 * `?restaurant=<slug>` query param. Previously duplicated verbatim in
 * ConfigController and MenuController; extracted here once a third
 * consumer (CustomerAuthController, Phase 6) needed the exact same
 * resolution logic.
 */
class RestaurantResolver
{
    public function resolve(Request $request): ?Restaurant
    {
        $host = $request->getHost();

        $domain = RestaurantDomain::withoutTenantScope()
            ->where('domain', $host)
            ->where('status', 'ACTIVE')
            ->first();

        if ($domain) {
            return Restaurant::find($domain->restaurant_id);
        }

        if ($slug = $request->query('restaurant')) {
            return Restaurant::where('slug', $slug)->first();
        }

        return null;
    }
}
