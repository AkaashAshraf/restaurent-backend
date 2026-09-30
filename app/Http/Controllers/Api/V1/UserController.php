<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $restaurant = $request->user()->restaurant;

        // User is deliberately not global-scoped (see model docblock) so
        // every listing must go through this explicit scope.
        $users = User::ofRestaurant($restaurant->id)->with('roles', 'branchAssignments')->get();

        return ApiResponse::success($users);
    }

    public function store(Request $request)
    {
        $restaurant = $request->user()->restaurant;

        $subscription = $restaurant->activeSubscription()->with('plan')->first();
        $userLimit = $subscription?->userLimit();

        if ($userLimit !== null) {
            $currentCount = User::ofRestaurant($restaurant->id)->count();
            if ($currentCount >= $userLimit) {
                return ApiResponse::error('VALIDATION_ERROR', "This restaurant has reached its user limit ({$userLimit}).", 422);
            }
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:50'],
            'password' => ['required', 'string', 'min:8'],
            'role_ids' => ['required', 'array', 'min:1'],
            'role_ids.*' => ['integer'],
            'branch_ids' => ['array'],
            'branch_ids.*' => ['integer', 'exists:branches,id'],
        ]);

        $roles = Role::availableTo($restaurant->id)->whereIn('id', $data['role_ids'])->get();

        $user = User::create([
            'restaurant_id' => $restaurant->id,
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => Hash::make($data['password']),
        ]);

        $user->roles()->sync($roles->pluck('id'));

        if (! empty($data['branch_ids'])) {
            foreach ($data['branch_ids'] as $branchId) {
                $user->branchAssignments()->create([
                    'branch_id' => $branchId,
                    'restaurant_id' => $restaurant->id,
                ]);
            }
        }

        AuditLog::create([
            'restaurant_id' => $restaurant->id,
            'user_id' => $request->user()->id,
            'action' => 'user.created',
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'changes' => ['new' => ['email' => $user->email, 'role_ids' => $data['role_ids']]],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return ApiResponse::success($user->load('roles', 'branchAssignments'), 201);
    }

    public function show(Request $request, int $user)
    {
        $restaurant = $request->user()->restaurant;
        $target = User::ofRestaurant($restaurant->id)->with('roles', 'branchAssignments')->findOrFail($user);

        return ApiResponse::success($target);
    }

    public function update(Request $request, int $user)
    {
        $restaurant = $request->user()->restaurant;
        $target = User::ofRestaurant($restaurant->id)->findOrFail($user);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'status' => ['sometimes', 'in:ACTIVE,INACTIVE'],
            'role_ids' => ['sometimes', 'array'],
            'role_ids.*' => ['integer'],
            'branch_ids' => ['sometimes', 'array'],
            'branch_ids.*' => ['integer', 'exists:branches,id'],
        ]);

        $target->update(array_intersect_key($data, array_flip(['name', 'phone', 'status'])));

        if (array_key_exists('role_ids', $data)) {
            $roles = Role::availableTo($restaurant->id)->whereIn('id', $data['role_ids'])->get();
            $target->roles()->sync($roles->pluck('id'));
        }

        if (array_key_exists('branch_ids', $data)) {
            $target->branchAssignments()->delete();
            foreach ($data['branch_ids'] as $branchId) {
                $target->branchAssignments()->create([
                    'branch_id' => $branchId,
                    'restaurant_id' => $restaurant->id,
                ]);
            }
        }

        AuditLog::create([
            'restaurant_id' => $restaurant->id,
            'user_id' => $request->user()->id,
            'action' => 'user.updated',
            'subject_type' => User::class,
            'subject_id' => $target->id,
            'changes' => ['new' => $data],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return ApiResponse::success($target->fresh(['roles', 'branchAssignments']));
    }
}
