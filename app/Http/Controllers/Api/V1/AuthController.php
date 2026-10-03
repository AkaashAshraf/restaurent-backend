<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\PlatformSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Unscoped by design: email must be looked up across all restaurants
        // before any tenant context can exist. See User model docblock.
        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        // Platform maintenance: only the Super Admin can get in.
        if (! $user->is_super_admin && PlatformSettings::inMaintenance()) {
            return ApiResponse::error('MAINTENANCE', PlatformSettings::maintenanceMessage(), 503);
        }

        if (! $user->is_super_admin && ! $user->isActive()) {
            return ApiResponse::error('FORBIDDEN', 'Your account has been deactivated.', 403);
        }

        if (! $user->is_super_admin && $user->restaurant && ! $user->restaurant->isOperational()) {
            return ApiResponse::error('RESTAURANT_INACTIVE', 'This restaurant is not currently active.', 403);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        // The Super Admin can limit how long a sign-in lasts (0 = until sign-out).
        $days = (int) PlatformSettings::get('session_days');
        $token = $user->createToken('api', ['*'], $days > 0 ? now()->addDays($days) : null)->plainTextToken;

        return ApiResponse::success([
            'token' => $token,
            'user' => $this->serializeUser($user),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return ApiResponse::success(['message' => 'Logged out.']);
    }

    public function me(Request $request)
    {
        return ApiResponse::success($this->serializeUser($request->user()));
    }

    private function serializeUser(User $user): array
    {
        $user->loadMissing('roles.permissions', 'branchAssignments');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_super_admin' => $user->is_super_admin,
            'restaurant_id' => $user->restaurant_id,
            'roles' => $user->roles->pluck('slug'),
            'permissions' => $user->roles->pluck('permissions')->flatten()->pluck('key')->unique()->values(),
            'accessible_branch_ids' => $user->accessibleBranchIds(),
        ];
    }
}
