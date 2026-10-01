<?php

namespace App\Services;

use App\Models\Restaurant;
use App\Models\RestaurantDomain;
use Illuminate\Http\Request;

/**
 * The one place "which restaurant is this public/pre-login request for"
 * gets answered: the `Host` header against `restaurant_domains` first
 * (a white-labeled custom domain), falling back to an explicit
 * `?restaurant=<slug>` query param. The customer app sends `X-App-Key`
 * instead, which wins over both. Previously duplicated verbatim in
 * ConfigController and MenuController; extracted here once a third
 * consumer (CustomerAuthController, Phase 6) needed the exact same
 * resolution logic.
 */
class RestaurantResolver
{
    public function resolve(Request $request): ?Restaurant
    {
        // A restaurant's own customer app identifies itself with its app key.
        // A key that was sent but matches nothing must NOT fall through to
        // Host/slug lookup — that would silently show a different restaurant.
        $key = $request->header('X-App-Key') ?: $request->query('app_key');
        if ($key !== null && $key !== '') {
            return Restaurant::where('app_key', trim((string) $key))->first();
        }

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
