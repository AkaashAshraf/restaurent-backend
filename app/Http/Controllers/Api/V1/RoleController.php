<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    public function index(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;

        $roles = Role::availableTo($restaurantId)->with('permissions')->get();

        return ApiResponse::success($roles);
    }

    public function permissions()
    {
        return ApiResponse::success(Permission::orderBy('module')->get());
    }

    public function store(Request $request)
    {
        $restaurant = $request->user()->restaurant;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash'],
            'scope' => ['required', 'in:RESTAURANT,BRANCH'],
            'permission_ids' => ['array'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
        ]);

        $role = Role::create([
            'restaurant_id' => $restaurant->id,
            'name' => $data['name'],
            'slug' => $data['slug'],
            'scope' => $data['scope'],
            'is_system' => false,
        ]);

        $role->permissions()->sync($data['permission_ids'] ?? []);

        return ApiResponse::success($role->load('permissions'), 201);
    }

    public function update(Request $request, int $role)
    {
        $restaurant = $request->user()->restaurant;

        $target = Role::availableTo($restaurant->id)
            ->where('restaurant_id', $restaurant->id) // custom roles only — system templates are read-only
            ->findOrFail($role);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'permission_ids' => ['sometimes', 'array'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
        ]);

        if (isset($data['name'])) {
            $target->update(['name' => $data['name']]);
        }

        if (array_key_exists('permission_ids', $data)) {
            $target->permissions()->sync($data['permission_ids']);
        }

        return ApiResponse::success($target->fresh('permissions'));
    }
}
