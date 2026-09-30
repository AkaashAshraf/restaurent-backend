<?php

namespace App\Http\Middleware;

use App\Exceptions\PermissionDeniedException;
use App\Models\Customer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The customer-facing mirror of `EnsureActorIsStaff` — a staff member's
 * bearer token must never be able to act as a customer (place an order
 * "as" them, read/write their addresses) just because it also passes
 * `auth:sanctum`.
 */
class EnsureActorIsCustomer
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() instanceof Customer) {
            throw new PermissionDeniedException;
        }

        return $next($request);
    }
}
