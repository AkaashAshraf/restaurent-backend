<?php

namespace App\Http\Middleware;

use App\Exceptions\PermissionDeniedException;
use App\Services\PermissionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves a branch id from the route (parameter name given as the
 * middleware argument, default "branch") and verifies the authenticated
 * user is allowed to operate on it — a restaurant-wide role passes
 * automatically, a branch-scoped role must have an explicit assignment
 * (spec #22/#160).
 */
class EnsureBranchAccess
{
    public function __construct(private PermissionService $permissions)
    {
    }

    public function handle(Request $request, Closure $next, string $routeParam = 'branch'): Response
    {
        $user = $request->user();

        if (! $user) {
            throw new PermissionDeniedException;
        }

        $branchId = $request->route($routeParam);

        // Route model binding may already have resolved it to a model.
        if (is_object($branchId)) {
            $branchId = $branchId->getKey();
        }

        if ($branchId === null) {
            $branchId = $request->input('branch_id');
        }

        if ($branchId === null) {
            return $next($request);
        }

        if (! $this->permissions->userCanAccessBranch($user, (int) $branchId)) {
            throw new PermissionDeniedException('You do not have access to this branch.');
        }

        return $next($request);
    }
}
