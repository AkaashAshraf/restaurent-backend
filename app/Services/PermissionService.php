<?php

namespace App\Services;

use App\Models\User;

/**
 * Single source of truth for "can this user do X". Kept as a thin,
 * explicit wrapper around User::hasPermission() so controllers/middleware
 * never inline permission checks or branch logic themselves.
 */
class PermissionService
{
    public function userHasPermission(User $user, string $permissionKey): bool
    {
        if ($user->is_super_admin) {
            return true;
        }

        if (! $user->isActive()) {
            return false;
        }

        return $user->hasPermission($permissionKey);
    }

    public function userCanAccessBranch(User $user, int $branchId): bool
    {
        if ($user->is_super_admin) {
            return true;
        }

        return $user->canAccessBranch($branchId);
    }
}
