<?php

namespace App\Http\Middleware;

use App\Exceptions\PermissionDeniedException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `auth:sanctum` authenticates any `HasApiTokens` model a bearer token
 * belongs to — as of Phase 6 that's `User` (staff) or `Customer`. The
 * staff pipeline below this middleware (`tenant`, `permission`,
 * `branch.access`, `AuthController`, ...) calls staff-only methods
 * (`hasPermission()`, `canAccessBranch()`, ...) that don't exist on
 * `Customer`, which would otherwise surface as an uncaught Error instead
 * of a clean 403 if a customer's token ever reached one of these routes.
 * This fails closed before any of that runs.
 */
class EnsureActorIsStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() instanceof User) {
            throw new PermissionDeniedException;
        }

        return $next($request);
    }
}
