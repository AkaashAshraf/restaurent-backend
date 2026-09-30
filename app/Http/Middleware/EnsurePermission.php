<?php

namespace App\Http\Middleware;

use App\Exceptions\PermissionDeniedException;
use App\Services\PermissionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware parameterized with a permission key, e.g.
 *   Route::middleware('permission:branches.create')->group(...)
 */
class EnsurePermission
{
    public function __construct(private PermissionService $permissions)
    {
    }

    public function handle(Request $request, Closure $next, string $permissionKey): Response
    {
        $user = $request->user();

        if (! $user) {
            throw new PermissionDeniedException;
        }

        if (! $this->permissions->userHasPermission($user, $permissionKey)) {
            throw new PermissionDeniedException;
        }

        return $next($request);
    }
}
