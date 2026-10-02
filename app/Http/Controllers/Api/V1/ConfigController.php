<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\Branch;
use App\Services\BranchHoursService;
use App\Services\FeatureService;
use App\Services\OrderService;
use App\Services\RestaurantResolver;
use App\Support\ApiResponse;
use App\Support\CustomerAppBranding;
use App\Support\TenantContext;
use Illuminate\Http\Request;

/**
 * Central config API (spec #41/#87/#124/#125). Generic employee apps and
 * the white-label customer app/website all read restaurant branding,
 * theme, currency and enabled features from here rather than hard-coding
 * anything — this is what makes them reusable across restaurants.
 */
class ConfigController extends Controller
{
    public function __construct(
        private FeatureService $features,
        private TenantContext $tenant,
        private RestaurantResolver $restaurants,
    ) {
    }

    /**
     * GET /api/v1/app/config — resolved from Host header or ?restaurant=
     * slug. No auth required (a generic app needs this before login even
     * exists on screen), so there is no authenticated user to derive a
     * tenant from. Resolving the restaurant by domain/slug here IS the
     * tenant-identification step for this endpoint, so once resolved we
     * explicitly establish the TenantContext for it — otherwise the
     * BelongsToTenant scope on Branch etc. would (correctly, by design)
     * refuse to return anything for a request with no tenant context.
     */
    public function appConfig(Request $request)
    {
        $restaurant = $this->restaurants->resolve($request);

        if (! $restaurant) {
            return ApiResponse::error('NOT_FOUND', 'No restaurant is configured for this server URL.', 404);
        }

        // This route has no `tenant` middleware (it's public, pre-login),
        // so nothing else resets the context on a worker-reuse runtime —
        // reset before setting, or a leaked bypass=true from an earlier
        // Super Admin request in the same worker would skip tenant scoping
        // entirely for this request. See TenantContext::reset().
        $this->tenant->reset()->setRestaurantId($restaurant->id);

        $config = $this->buildConfig($restaurant);

        // The customer app's look (name, colours, logo, banners...) and the
        // ordering rules it needs before login — public, so only what a
        // customer would see anyway.
        $config['branding'] = CustomerAppBranding::resolve($restaurant);
        $settings = $restaurant->settings;
        $config['ordering'] = [
            // The customer app only offers delivery and/or takeaway (the
            // restaurant picks in its portal); dine-in is for staff.
            'order_types' => $settings ? $settings->customerOrderTypes() : ['DELIVERY', 'TAKEAWAY'],
            'min_order_amount' => (float) ($settings?->min_order_amount ?? 0),
            'delivery_enabled' => $settings ? in_array('DELIVERY', $settings->customerOrderTypes(), true) : true,
            'delivery_fee' => (float) ($settings?->delivery_fee ?? 0),
            'free_delivery_threshold' => $settings?->free_delivery_threshold !== null ? (float) $settings->free_delivery_threshold : null,
            // Customers pay online, which is charged the card rate.
            'tax_enabled' => (bool) ($settings?->tax_enabled ?? false),
            'online_tax_percentage' => $settings ? $settings->taxRateFor('ONLINE') : 0,
            // Paying cash (on delivery / at the restaurant) is taxed at the cash rate.
            'cash_tax_percentage' => $settings ? $settings->taxRateFor('CASH') : 0,
        ];
        // Which branches limit delivery to drawn zones (so the app knows to
        // ask for a pinned location before offering delivery there).
        $zoneBranchIds = config('delivery.zones_enabled', false)
            ? \App\Models\DeliveryZone::where('is_active', true)->pluck('branch_id')->unique()->all()
            : [];
        $config['branches'] = collect($config['branches'])->map(
            fn ($b) => $b + ['has_delivery_zones' => in_array($b['id'], $zoneBranchIds, true)]
        )->values()->all();
        // Which sign-in options the app should offer (set by the super admin).
        $branding = $restaurant->app_branding ?: [];
        $config['auth'] = [
            'google' => [
                'enabled' => filled($branding['google_web_client_id'] ?? null),
                'web_client_id' => $branding['google_web_client_id'] ?? null,
                'ios_client_id' => $branding['google_ios_client_id'] ?? null,
            ],
            'apple' => ['enabled' => filled($branding['ios_bundle_id'] ?? null)],
        ];
        $config['restaurant']['operational'] = $restaurant->isOperational();
        $config['restaurant']['description'] = $restaurant->description;
        $config['restaurant']['phone'] = $restaurant->phone;
        $config['restaurant']['address'] = $restaurant->address;

        return ApiResponse::success($config);
    }

    /**
     * GET /api/v1/app/tables?branch_id= — a branch's tables, so a customer
     * ordering dine-in can say which one they're sitting at.
     */
    public function appTables(Request $request)
    {
        $restaurant = $this->restaurants->resolve($request);

        if (! $restaurant) {
            return ApiResponse::error('NOT_FOUND', 'No restaurant is configured for this server URL.', 404);
        }

        $this->tenant->reset()->setRestaurantId($restaurant->id);

        $data = $request->validate(['branch_id' => ['required', 'integer']]);

        $tables = \App\Models\Table::where('branch_id', $data['branch_id'])
            ->orderBy('table_number')
            ->get(['id', 'branch_id', 'table_number', 'section', 'capacity']);

        return ApiResponse::success($tables);
    }

    /**
     * GET /api/v1/app/hours[?branch_id=] — opening hours of every active
     * branch (or one), so a customer can see when each branch is open and
     * when it takes dine-in, takeaway and delivery orders. Public. Each
     * service carries its weekly days, whether it has its own hours
     * (`custom`) or follows the branch's, and whether it is open right now.
     */
    public function appHours(Request $request, BranchHoursService $hours)
    {
        $restaurant = $this->restaurants->resolve($request);

        if (! $restaurant) {
            return ApiResponse::error('NOT_FOUND', 'No restaurant is configured for this server URL.', 404);
        }

        $this->tenant->reset()->setRestaurantId($restaurant->id);

        $data = $request->validate(['branch_id' => ['nullable', 'integer']]);

        $restaurant->loadMissing('settings');
        $types = collect($restaurant->settings?->customerOrderTypes() ?? ['DELIVERY', 'TAKEAWAY'])
            ->filter(fn ($type) => $this->features->isEnabled($restaurant, $type))
            ->values()
            ->all();
        $timezone = in_array($restaurant->timezone, \DateTimeZone::listIdentifiers(), true) ? $restaurant->timezone : 'UTC';

        $branches = Branch::where('status', 'ACTIVE')
            ->when($data['branch_id'] ?? null, fn ($q, $id) => $q->where('id', $id))
            ->orderBy('priority')
            ->get()
            ->map(fn (Branch $branch) => [
                'id' => $branch->id,
                'name' => $branch->name,
                'address' => $branch->address,
                'city' => $branch->city,
                'phone' => $branch->phone,
            ] + $hours->describe($branch, $timezone, null, $types))
            ->values();

        $now = \Carbon\CarbonImmutable::now($timezone);

        return ApiResponse::success([
            'timezone' => $timezone,
            // The restaurant's own clock, so the app can highlight "today".
            'now' => $now->toIso8601String(),
            'today' => $now->dayOfWeek,
            'order_types' => $types,
            'branches' => $branches,
        ]);
    }

    /**
     * GET /api/v1/app/delivery-quote?branch_id=&latitude=&longitude=&subtotal=
     * — can this branch deliver to that point, and what would it cost? Public
     * (the customer sees the fee before signing in); answers with the same
     * zone and fee rules a DELIVERY order is placed under.
     */
    public function appDeliveryQuote(Request $request, OrderService $orders)
    {
        $restaurant = $this->restaurants->resolve($request);

        if (! $restaurant) {
            return ApiResponse::error('NOT_FOUND', 'No restaurant is configured for this server URL.', 404);
        }

        $this->tenant->reset()->setRestaurantId($restaurant->id);

        $data = $request->validate([
            'branch_id' => ['required', 'integer'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'subtotal' => ['nullable', 'numeric', 'min:0'],
        ]);

        $branch = Branch::where('status', 'ACTIVE')->find($data['branch_id']);
        if (! $branch) {
            return ApiResponse::error('NOT_FOUND', 'That branch is not available.', 404);
        }

        $restaurant->loadMissing('settings');
        $branch->loadMissing('settings');

        return ApiResponse::success($orders->deliveryQuote(
            $restaurant,
            $branch,
            isset($data['latitude']) ? (float) $data['latitude'] : null,
            isset($data['longitude']) ? (float) $data['longitude'] : null,
            (float) ($data['subtotal'] ?? 0),
        ));
    }

    /**
     * GET /api/v1/app/delivery-branches?latitude=&longitude=&subtotal= — which
     * branches deliver to this point, best first: a branch whose drawn zone
     * covers it comes before one that simply delivers everywhere, nearest
     * first within each. Lets the app move an order to the right branch when
     * the customer picked the wrong one. Public, like the quote.
     */
    public function appDeliveryBranches(Request $request, OrderService $orders)
    {
        $restaurant = $this->restaurants->resolve($request);

        if (! $restaurant) {
            return ApiResponse::error('NOT_FOUND', 'No restaurant is configured for this server URL.', 404);
        }

        $this->tenant->reset()->setRestaurantId($restaurant->id);

        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'subtotal' => ['nullable', 'numeric', 'min:0'],
        ]);
        $lat = (float) $data['latitude'];
        $lng = (float) $data['longitude'];

        $restaurant->loadMissing('settings');
        $out = [];

        foreach (Branch::with('settings')->where('status', 'ACTIVE')->orderBy('priority')->get() as $branch) {
            if ($branch->settings?->delivery_enabled === false) {
                continue;
            }
            $quote = $orders->deliveryQuote($restaurant, $branch, $lat, $lng, (float) ($data['subtotal'] ?? 0));
            if (! $quote['deliverable']) {
                continue;
            }
            $distance = ($branch->latitude !== null && $branch->longitude !== null)
                ? round($this->distanceKm($lat, $lng, (float) $branch->latitude, (float) $branch->longitude), 1)
                : null;

            $out[] = [
                'branch_id' => $branch->id,
                'name' => $branch->name,
                'address' => $branch->address,
                'zone' => $quote['zone'],
                'zones_configured' => $quote['zones_configured'],
                'fee' => $quote['fee'],
                'distance_km' => $distance,
            ];
        }

        usort($out, fn ($a, $b) => [! $a['zones_configured'], $a['distance_km'] ?? INF] <=> [! $b['zones_configured'], $b['distance_km'] ?? INF]);

        return ApiResponse::success(['branches' => $out]);
    }

    private function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /** GET /api/v1/config — authenticated variant, resolves restaurant from the current user. */
    public function config(Request $request)
    {
        $restaurant = $request->user()?->restaurant;

        if (! $restaurant) {
            return ApiResponse::error('NOT_FOUND', 'No restaurant associated with this account.', 404);
        }

        return ApiResponse::success($this->buildConfig($restaurant, includeSettings: true));
    }

    private function buildConfig(Restaurant $restaurant, bool $includeSettings = false): array
    {
        $restaurant->loadMissing('settings', 'branches');

        $config = [
            'restaurant' => [
                'id' => $restaurant->id,
                'name' => $restaurant->name,
                'logo' => $restaurant->logo,
                'currency' => $restaurant->currency,
                'timezone' => $restaurant->timezone,
                'status' => $restaurant->status,
            ],
            'theme' => $restaurant->theme ?: [
                'primaryColor' => '#E53935',
                'secondaryColor' => '#212121',
            ],
            'branches' => $restaurant->branches->map(fn ($b) => [
                'id' => $b->id,
                'name' => $b->name,
                'status' => $b->status,
                'latitude' => $b->latitude,
                'longitude' => $b->longitude,
            ]),
            'features' => array_fill_keys($this->features->enabledFeatureKeys($restaurant), true),
            'app' => [
                'minimum_version' => '1.0.0',
                'latest_version' => '1.0.0',
                'force_update' => false,
            ],
        ];

        if ($includeSettings && $restaurant->settings) {
            $config['ordering'] = $restaurant->settings->only([
                'order_types', 'min_order_amount', 'default_prep_time_minutes',
            ]);
            $config['tax'] = [
                'enabled' => (bool) $restaurant->settings->tax_enabled,
                'cash_percentage' => $restaurant->settings->taxRateFor('CASH'),
                'card_percentage' => $restaurant->settings->taxRateFor('CARD'),
                'fbr_number' => $restaurant->settings->fbr_number,
            ];
            $config['kitchen'] = ['order_types' => $restaurant->settings->kitchenOrderTypes()];
            $config['delivery'] = $restaurant->settings->only([
                'delivery_enabled', 'delivery_fee', 'free_delivery_threshold',
            ]);
        }

        return $config;
    }
}
