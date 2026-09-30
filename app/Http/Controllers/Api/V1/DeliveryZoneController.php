<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\PermissionDeniedException;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\DeliveryZone;
use App\Services\PermissionService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DeliveryZoneController extends Controller
{
    public function __construct(private PermissionService $permissions)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $query = DeliveryZone::query()->with('branch');

        if (! $user->is_super_admin && ! $user->hasRestaurantWideAccess()) {
            $query->whereIn('branch_id', $user->accessibleBranchIds() ?: [0]);
        }

        if ($branchId = $request->query('branch_id')) {
            $query->where('branch_id', $branchId);
        }

        return ApiResponse::success($query->latest()->get());
    }

    /**
     * branch_id comes from the request body, not a route segment — same
     * `branch.access` middleware fallback-to-input pattern used by
     * TableController::store and OrderController::store.
     */
    public function store(Request $request)
    {
        $restaurant = $request->user()->restaurant;
        $data = $this->validated($request);

        Branch::findOrFail($data['branch_id']);
        $data['is_active'] = $data['is_active'] ?? true;

        $zone = DeliveryZone::create($data + ['restaurant_id' => $restaurant->id]);

        AuditLog::create([
            'restaurant_id' => $restaurant->id,
            'branch_id' => $zone->branch_id,
            'user_id' => $request->user()->id,
            'action' => 'delivery_zone.created',
            'subject_type' => DeliveryZone::class,
            'subject_id' => $zone->id,
            'changes' => ['new' => $data],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return ApiResponse::success($zone, 201);
    }

    public function show(Request $request, int $deliveryZone)
    {
        $zone = DeliveryZone::findOrFail($deliveryZone);
        $this->assertBranchAccess($request, $zone->branch_id);

        return ApiResponse::success($zone->load('branch'));
    }

    public function update(Request $request, int $deliveryZone)
    {
        $zone = DeliveryZone::findOrFail($deliveryZone);
        $this->assertBranchAccess($request, $zone->branch_id);

        $data = $this->validated($request, updating: true);

        $before = $zone->only(array_keys($data));
        $zone->update($data);

        AuditLog::create([
            'restaurant_id' => $zone->restaurant_id,
            'branch_id' => $zone->branch_id,
            'user_id' => $request->user()->id,
            'action' => 'delivery_zone.updated',
            'subject_type' => DeliveryZone::class,
            'subject_id' => $zone->id,
            'changes' => ['old' => $before, 'new' => $data],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return ApiResponse::success($zone->fresh());
    }

    public function destroy(Request $request, int $deliveryZone)
    {
        $zone = DeliveryZone::findOrFail($deliveryZone);
        $this->assertBranchAccess($request, $zone->branch_id);
        $zone->delete();

        AuditLog::create([
            'restaurant_id' => $zone->restaurant_id,
            'branch_id' => $zone->branch_id,
            'user_id' => $request->user()->id,
            'action' => 'delivery_zone.deleted',
            'subject_type' => DeliveryZone::class,
            'subject_id' => $zone->id,
            'changes' => [],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return ApiResponse::success(['deleted' => true]);
    }

    /**
     * `store` requires branch_id and a shape consistent with `type`;
     * `update` allows any subset of fields ($sometimes) since the type
     * itself doesn't have to be re-sent to tweak, say, the fee override.
     */
    private function validated(Request $request, bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return $request->validate([
            'branch_id' => [$updating ? 'sometimes' : 'required', 'integer'],
            'name' => [$required, 'string', 'max:100'],
            'type' => [$required, Rule::in(['RADIUS', 'POLYGON'])],
            'center_latitude' => ['required_if:type,RADIUS', 'nullable', 'numeric', 'between:-90,90'],
            'center_longitude' => ['required_if:type,RADIUS', 'nullable', 'numeric', 'between:-180,180'],
            'radius_km' => ['required_if:type,RADIUS', 'nullable', 'numeric', 'min:0.1'],
            'polygon' => ['required_if:type,POLYGON', 'nullable', 'array', 'min:3'],
            'polygon.*.lat' => ['required_with:polygon', 'numeric', 'between:-90,90'],
            'polygon.*.lng' => ['required_with:polygon', 'numeric', 'between:-180,180'],
            'delivery_fee_override' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    /**
     * Same check `EnsureBranchAccess` performs, applied manually: the
     * route parameter here is a delivery-zone id, not a branch id.
     */
    private function assertBranchAccess(Request $request, int $branchId): void
    {
        if (! $this->permissions->userCanAccessBranch($request->user(), $branchId)) {
            throw new PermissionDeniedException('You do not have access to this branch.');
        }
    }
}
