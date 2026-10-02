<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\RestaurantResolver;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Two short lists for the customer app's Home: what sells best at a branch, and
 * what this customer keeps ordering. Both return product ids (best first); the
 * app looks them up in the branch menu it already has, so names, photos,
 * prices and availability always agree with the menu.
 */
class ProductHighlightsController extends Controller
{
    private const BEST_SELLER_DAYS = 60;

    public function __construct(private TenantContext $tenant, private RestaurantResolver $restaurants)
    {
    }

    /** GET /api/v1/app/best-sellers?branch=id — public. */
    public function bestSellers(Request $request)
    {
        $restaurant = $this->restaurants->resolve($request);
        if (! $restaurant) {
            return ApiResponse::error('NOT_FOUND', 'No restaurant is configured for this server URL.', 404);
        }
        $this->tenant->reset()->setRestaurantId($restaurant->id);

        $rows = $this->ranked($restaurant->id)
            ->when($request->query('branch'), fn ($q, $branch) => $q->where('orders.branch_id', (int) $branch))
            ->where('orders.created_at', '>=', now()->subDays(self::BEST_SELLER_DAYS))
            ->limit(12)
            ->get();

        return ApiResponse::success(['product_ids' => $rows->pluck('product_id')->map(fn ($id) => (int) $id)->values()]);
    }

    /** GET /api/v1/customer/favorites — the signed-in customer's most-ordered products. */
    public function favorites(Request $request)
    {
        $customer = $request->user();

        $rows = $this->ranked($customer->restaurant_id)
            ->where('orders.customer_id', $customer->id)
            ->limit(12)
            ->get();

        return ApiResponse::success(['product_ids' => $rows->pluck('product_id')->map(fn ($id) => (int) $id)->values()]);
    }

    /** Products ordered most (units), skipping cancelled orders and deleted or switched-off products. */
    private function ranked(int $restaurantId)
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->where('orders.restaurant_id', $restaurantId)
            ->where('orders.status', '!=', 'CANCELLED')
            ->whereNull('products.deleted_at')
            ->where('products.status', 'ACTIVE')
            ->groupBy('order_items.product_id')
            ->orderByRaw('SUM(order_items.quantity) DESC')
            ->orderBy('order_items.product_id')
            ->select('order_items.product_id', DB::raw('SUM(order_items.quantity) as units'));
    }
}
