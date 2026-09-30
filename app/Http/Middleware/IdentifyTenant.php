<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * First link in the authorization chain (spec #121): once Sanctum has
 * authenticated the user, this establishes which restaurant/branches they
 * may operate on for the rest of the request. Every tenant-scoped model
 * query relies on this having run.
 *
 * Reused as-is for the customer-facing pipeline (Phase 6): `auth:sanctum`
 * authenticates any `HasApiTokens` model, so `$user` here can be a
 * `Customer` too, not just staff. A `Customer` gets the exact same
 * restaurant-scoping treatment (see below) but is never staff-specific
 * beyond that — the `staff.guard`/`customer.guard` middleware (see
 * `routes/api.php`) is what actually keeps the two pipelines from mixing.
 */
class IdentifyTenant
{
    public function __construct(private TenantContext $context)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        // Always start from a clean slate — see TenantContext::reset() for
        // why this can't be skipped even though it's a no-op on a
        // fresh-per-request runtime.
        $this->context->reset();

        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        if (! $user instanceof User) {
            // Non-staff actor (currently: Customer). No is_super_admin
            // concept and no staff branch assignments to derive — just
            // the restaurant scope itself, with unrestricted branch access
            // within it (null = "all branches", same as a restaurant-wide
            // staff role gets).
            if ($user->restaurant_id !== null) {
                $this->context->setRestaurantId($user->restaurant_id);
            }

            return $next($request);
        }

        if ($user->is_super_admin) {
            $this->context->setSuperAdmin(true);
            // Super Admin routes operate across restaurants explicitly;
            // tenant scoping is bypassed and individual controllers select
            // the restaurant_id they need from the route/request.
            $this->context->bypass(true);

            return $next($request);
        }

        if ($user->restaurant_id === null) {
            return $next($request);
        }

        $this->context->setRestaurantId($user->restaurant_id);
        $this->context->setBranchIds($user->accessibleBranchIds());

        return $next($request);
    }
}
