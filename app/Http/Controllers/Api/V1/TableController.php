<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\PermissionDeniedException;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Table;
use App\Services\PermissionService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class TableController extends Controller
{
    public function __construct(private PermissionService $permissions)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $query = Table::query()->with('branch', 'activeOrder');

        if (! $user->is_super_admin && ! $user->hasRestaurantWideAccess()) {
            $query->whereIn('branch_id', $user->accessibleBranchIds() ?: [0]);
        }

        if ($branchId = $request->query('branch_id')) {
            $query->where('branch_id', $branchId);
        }

        return ApiResponse::success($query->orderBy('table_number')->get());
    }

    /**
     * branch_id comes from the request body, not a route segment, so this
     * relies on `branch.access` middleware's fallback to `input('branch_id')`
     * rather than a `{branch}` route parameter (see routes/api.php).
     */
    public function store(Request $request)
    {
        $restaurant = $request->user()->restaurant;

        $data = $request->validate([
            'branch_id' => ['required', 'integer'],
            'table_number' => ['required', 'string', 'max:50'],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'section' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:AVAILABLE,OCCUPIED,RESERVED,UNAVAILABLE'],
        ]);

        Branch::findOrFail($data['branch_id']);
        $data['status'] = $data['status'] ?? 'AVAILABLE';

        $table = Table::create($data + ['restaurant_id' => $restaurant->id]);

        AuditLog::create([
            'restaurant_id' => $restaurant->id,
            'branch_id' => $table->branch_id,
            'user_id' => $request->user()->id,
            'action' => 'table.created',
            'subject_type' => Table::class,
            'subject_id' => $table->id,
            'changes' => ['new' => $data],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return ApiResponse::success($table, 201);
    }

    public function show(Request $request, int $table)
    {
        $table = Table::findOrFail($table);
        $this->assertBranchAccess($request, $table->branch_id);

        return ApiResponse::success($table->load('branch', 'activeOrder'));
    }

    public function update(Request $request, int $table)
    {
        $table = Table::findOrFail($table);
        $this->assertBranchAccess($request, $table->branch_id);

        $data = $request->validate([
            'table_number' => ['sometimes', 'string', 'max:50'],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'section' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:AVAILABLE,OCCUPIED,RESERVED,UNAVAILABLE'],
        ]);

        $before = $table->only(array_keys($data));
        $table->update($data);

        AuditLog::create([
            'restaurant_id' => $table->restaurant_id,
            'branch_id' => $table->branch_id,
            'user_id' => $request->user()->id,
            'action' => 'table.updated',
            'subject_type' => Table::class,
            'subject_id' => $table->id,
            'changes' => ['old' => $before, 'new' => $data],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return ApiResponse::success($table->fresh());
    }

    public function destroy(Request $request, int $table)
    {
        $table = Table::findOrFail($table);
        $this->assertBranchAccess($request, $table->branch_id);
        $table->delete();

        AuditLog::create([
            'restaurant_id' => $table->restaurant_id,
            'branch_id' => $table->branch_id,
            'user_id' => $request->user()->id,
            'action' => 'table.deleted',
            'subject_type' => Table::class,
            'subject_id' => $table->id,
            'changes' => [],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return ApiResponse::success(['deleted' => true]);
    }

    /**
     * Same check `EnsureBranchAccess` performs, applied manually: the route
     * parameter here is a table id, not a branch id, so the middleware
     * has nothing to resolve a branch from on show/update/destroy.
     */
    private function assertBranchAccess(Request $request, int $branchId): void
    {
        if (! $this->permissions->userCanAccessBranch($request->user(), $branchId)) {
            throw new PermissionDeniedException('You do not have access to this branch.');
        }
    }
}
