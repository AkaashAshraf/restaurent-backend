<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Feature;
use App\Models\Branch;
use App\Models\Restaurant;
use App\Models\RestaurantFeature;
use App\Models\Role;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class RestaurantController extends Controller
{
    public function index(Request $request)
    {
        $query = Restaurant::query()->withCount('branches');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return ApiResponse::success($query->orderBy('name')->paginate(50));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string', 'max:50'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'timezone' => ['sometimes', 'timezone:all'],
            'owner_name' => ['required', 'string', 'max:255'],
            'owner_email' => ['required', 'email', 'unique:users,email'],
            'owner_password' => ['required', 'string', 'min:8'],
        ]);

        $restaurant = Restaurant::create([
            'name' => $data['name'],
            'legal_name' => $data['legal_name'] ?? null,
            'slug' => $this->uniqueSlug($data['name']),
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'currency' => $data['currency'] ?? 'USD',
            'timezone' => $data['timezone'] ?? 'UTC',
            'status' => 'ACTIVE',
        ]);

        $restaurant->settings()->create([]);

        $ownerRole = Role::where('restaurant_id', null)->where('slug', 'restaurant-owner')->first();

        $owner = User::create([
            'restaurant_id' => $restaurant->id,
            'name' => $data['owner_name'],
            'email' => $data['owner_email'],
            'password' => Hash::make($data['owner_password']),
            'status' => 'ACTIVE',
        ]);

        if ($ownerRole) {
            $owner->roles()->sync([$ownerRole->id]);
        }

        AuditLog::create([
            'restaurant_id' => $restaurant->id,
            'user_id' => $request->user()->id,
            'action' => 'restaurant.created',
            'subject_type' => Restaurant::class,
            'subject_id' => $restaurant->id,
            'changes' => ['new' => ['name' => $restaurant->name]],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return ApiResponse::success([
            'restaurant' => $restaurant,
            'owner' => $owner,
        ], 201);
    }

    public function show(Restaurant $restaurant)
    {
        $restaurant->load(
            'settings',
            'activeSubscription.plan',
            'activeSubscription.featureOverrides.feature',
            'featureOverrides.feature',
        );

        // Branch is tenant-scoped, and a super admin has no tenant — through the relation it
        // would always come back empty. Ask for this restaurant's branches explicitly.
        $restaurant->setRelation('branches', Branch::withoutGlobalScopes()
            ->where('restaurant_id', $restaurant->id)->orderBy('name')->get());

        return ApiResponse::success($restaurant);
    }

    public function update(Request $request, Restaurant $restaurant)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string', 'max:50'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'timezone' => ['sometimes', 'timezone:all'],
        ]);

        $restaurant->update($data);

        return ApiResponse::success($restaurant->fresh());
    }

    public function updateStatus(Request $request, Restaurant $restaurant)
    {
        $data = $request->validate([
            'status' => ['required', 'in:ACTIVE,INACTIVE,SUSPENDED,EXPIRED'],
        ]);

        $before = $restaurant->status;
        $restaurant->update(['status' => $data['status']]);

        AuditLog::create([
            'restaurant_id' => $restaurant->id,
            'user_id' => $request->user()->id,
            'action' => 'restaurant.status_changed',
            'subject_type' => Restaurant::class,
            'subject_id' => $restaurant->id,
            'changes' => ['old' => (string) $before, 'new' => $data['status']],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return ApiResponse::success($restaurant->fresh());
    }

    public function toggleFeatureOverride(Request $request, Restaurant $restaurant, Feature $feature)
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        $override = RestaurantFeature::updateOrCreate(
            ['restaurant_id' => $restaurant->id, 'feature_id' => $feature->id],
            ['enabled' => $data['enabled']]
        );

        app(\App\Services\FeatureService::class)->forget($restaurant);

        AuditLog::create([
            'restaurant_id' => $restaurant->id,
            'user_id' => $request->user()->id,
            'action' => 'restaurant.feature_override_changed',
            'subject_type' => Feature::class,
            'subject_id' => $feature->id,
            'changes' => ['new' => ['feature' => $feature->key, 'enabled' => $data['enabled']]],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return ApiResponse::success($override);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $i = 1;

        while (Restaurant::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
