<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Restaurant;
use App\Services\MenuService;
use App\Services\RestaurantResolver;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\Request;

/**
 * The resolved, branch-aware menu — what the customer app/website and
 * kitchen/waiter apps actually render, as opposed to the raw CRUD
 * resources in CategoryController/ProductController that the admin panel
 * edits. Mirrors ConfigController's public/authenticated split (spec
 * #41/#87): a generic app needs this before login exists on screen.
 */
class MenuController extends Controller
{
    public function __construct(
        private MenuService $menu,
        private TenantContext $tenant,
        private RestaurantResolver $restaurants,
    ) {
    }

    /** GET /api/v1/app/menu — public, resolved by Host header, ?restaurant=slug, and optional ?branch=id. */
    public function publicMenu(Request $request)
    {
        $restaurant = $this->restaurants->resolve($request);

        if (! $restaurant) {
            return ApiResponse::error('NOT_FOUND', 'No restaurant is configured for this server URL.', 404);
        }

        // No `tenant` middleware on this public route — reset before
        // setting, same reasoning as ConfigController::appConfig(), so a
        // leaked bypass=true from an earlier Super Admin request in the
        // same worker can't skip tenant scoping for this request.
        $this->tenant->reset()->setRestaurantId($restaurant->id);

        $branch = $this->resolveBranch($request, $restaurant);

        return ApiResponse::success([
            'branch_id' => $branch?->id,
            'categories' => $this->menu->buildMenu($restaurant, $branch, onlyAvailable: true),
        ]);
    }

    /** GET /api/v1/menu — authenticated, restaurant resolved from the current user. */
    public function menu(Request $request)
    {
        $restaurant = $request->user()->restaurant;

        if (! $restaurant) {
            return ApiResponse::error('NOT_FOUND', 'No restaurant associated with this account.', 404);
        }

        $branch = $this->resolveBranch($request, $restaurant);

        return ApiResponse::success([
            'branch_id' => $branch?->id,
            'categories' => $this->menu->buildMenu($restaurant, $branch, onlyAvailable: false),
        ]);
    }

    private function resolveBranch(Request $request, Restaurant $restaurant): ?Branch
    {
        $branchId = $request->query('branch');

        if (! $branchId) {
            return null;
        }

        return Branch::where('restaurant_id', $restaurant->id)->find($branchId);
    }

}
