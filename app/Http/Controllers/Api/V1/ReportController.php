<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\OrderValidationException;
use App\Exceptions\PermissionDeniedException;
use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Services\PermissionService;
use App\Services\ReportService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Every report defaults to the trailing 30 days when `from`/`to` aren't
 * given, and is scoped to whatever branches the requesting user can
 * already see — a branch-scoped user (e.g. `branch-manager`) gets
 * numbers for their own branch(es) only, exactly like
 * `OrderController::index()`'s own branch-access filtering, just applied
 * to an aggregate instead of a list. Coupon usage is the one exception —
 * see `coupons()` — since a coupon has no branch dimension at all.
 */
class ReportController extends Controller
{
    public function __construct(
        private ReportService $reports,
        private PermissionService $permissions,
    ) {
    }

    public function sales(Request $request)
    {
        [$restaurant, $from, $to, $branchIds, $branchId] = $this->context($request);

        return ApiResponse::success($this->reports->salesSummary($restaurant, $from, $to, $branchIds, $branchId));
    }

    public function payments(Request $request)
    {
        [$restaurant, $from, $to, $branchIds, $branchId] = $this->context($request);

        return ApiResponse::success($this->reports->paymentsSummary($restaurant, $from, $to, $branchIds, $branchId));
    }

    public function topProducts(Request $request)
    {
        [$restaurant, $from, $to, $branchIds, $branchId] = $this->context($request);
        $limit = (int) ($request->query('limit') ?: 10);

        return ApiResponse::success(
            $this->reports->topProducts($restaurant, $from, $to, $branchIds, $branchId, max(1, min(100, $limit)))
        );
    }

    public function coupons(Request $request)
    {
        $restaurant = $request->user()->restaurant;
        [$from, $to] = $this->dateRange($request);

        return ApiResponse::success($this->reports->couponUsage($restaurant, $from, $to));
    }

    /** @return array{0: Restaurant, 1: Carbon, 2: Carbon, 3: ?array, 4: ?int} */
    private function context(Request $request): array
    {
        $user = $request->user();
        $restaurant = $user->restaurant;
        [$from, $to] = $this->dateRange($request);

        $branchIds = null;
        if (! $user->is_super_admin && ! $user->hasRestaurantWideAccess()) {
            $branchIds = $user->accessibleBranchIds() ?: [0];
        }

        $branchId = null;
        if ($request->filled('branch_id')) {
            $branchId = (int) $request->query('branch_id');

            if (! $this->permissions->userCanAccessBranch($user, $branchId)) {
                throw new PermissionDeniedException('You do not have access to this branch.');
            }
        }

        return [$restaurant, $from, $to, $branchIds, $branchId];
    }

    /** Defaults to the trailing 30 days (today inclusive) when not given. */
    private function dateRange(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'branch_id' => ['nullable', 'integer'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $to = isset($data['to']) ? Carbon::parse($data['to'])->endOfDay() : now()->endOfDay();
        $from = isset($data['from']) ? Carbon::parse($data['from'])->startOfDay() : $to->copy()->subDays(29)->startOfDay();

        if ($from->gt($to)) {
            throw new OrderValidationException('`from` must be on or before `to`.');
        }

        return [$from, $to];
    }
}
