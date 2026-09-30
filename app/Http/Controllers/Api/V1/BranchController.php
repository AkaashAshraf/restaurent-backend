<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Services\BranchService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    public function __construct(private BranchService $branches)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $query = Branch::query()->with('settings');

        // Branch-scoped users only ever see their assigned branches; this is
        // a filter, not just a UI hide — the tenant scope already limits to
        // the restaurant, this narrows further to accessible branches.
        if (! $user->is_super_admin && ! $user->hasRestaurantWideAccess()) {
            $query->whereIn('id', $user->accessibleBranchIds() ?: [0]);
        }

        return ApiResponse::success($query->orderBy('priority')->get());
    }

    public function store(Request $request)
    {
        $restaurant = $request->user()->restaurant;

        $this->branches->assertCanCreateBranch($restaurant);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'branch_code' => ['required', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email'],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string'],
            'state' => ['nullable', 'string'],
            'country' => ['nullable', 'string'],
            'postal_code' => ['nullable', 'string'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'priority' => ['nullable', 'integer', 'min:0'],
        ]);

        $branch = Branch::create($data + ['restaurant_id' => $restaurant->id]);

        AuditLog::create([
            'restaurant_id' => $restaurant->id,
            'branch_id' => $branch->id,
            'user_id' => $request->user()->id,
            'action' => 'branch.created',
            'subject_type' => Branch::class,
            'subject_id' => $branch->id,
            'changes' => ['new' => $data],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return ApiResponse::success($branch, 201);
    }

    /**
     * Note: these take a raw int id and load the model here rather than
     * using implicit route-model-binding. Implicit binding is resolved by
     * SubstituteBindings, which runs as part of the global "api" middleware
     * group — before our own "tenant" route middleware has set the
     * TenantContext. Loading it here guarantees the tenant scope (and the
     * branch.access check) has already been established by the time the
     * lookup runs.
     */
    public function show(Request $request, int $branch)
    {
        $branch = Branch::findOrFail($branch);

        return ApiResponse::success($branch->load('settings', 'hours'));
    }

    public function update(Request $request, int $branch)
    {
        $branch = Branch::findOrFail($branch);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email'],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string'],
            'state' => ['nullable', 'string'],
            'country' => ['nullable', 'string'],
            'postal_code' => ['nullable', 'string'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'priority' => ['nullable', 'integer', 'min:0'],
        ]);

        $before = $branch->only(array_keys($data));
        $branch->update($data);

        AuditLog::create([
            'restaurant_id' => $branch->restaurant_id,
            'branch_id' => $branch->id,
            'user_id' => $request->user()->id,
            'action' => 'branch.updated',
            'subject_type' => Branch::class,
            'subject_id' => $branch->id,
            'changes' => ['old' => $before, 'new' => $data],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return ApiResponse::success($branch->fresh());
    }

    public function updateStatus(Request $request, int $branch)
    {
        $branch = Branch::findOrFail($branch);

        $data = $request->validate([
            'status' => ['required', 'in:ACTIVE,INACTIVE,TEMPORARILY_CLOSED,PERMANENTLY_CLOSED'],
        ]);

        $before = $branch->status;
        $branch->update(['status' => $data['status']]);

        AuditLog::create([
            'restaurant_id' => $branch->restaurant_id,
            'branch_id' => $branch->id,
            'user_id' => $request->user()->id,
            'action' => 'branch.status_changed',
            'subject_type' => Branch::class,
            'subject_id' => $branch->id,
            'changes' => ['old' => (string) $before, 'new' => $data['status']],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return ApiResponse::success($branch->fresh());
    }
}
