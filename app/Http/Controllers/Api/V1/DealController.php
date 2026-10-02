<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Deal;
use App\Models\Product;
use App\Services\DealService;
use App\Services\RestaurantResolver;
use App\Support\ApiResponse;
use App\Support\CustomerAppBranding;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Combo deals. The restaurant's staff manage them (menu.* permissions, like the
 * rest of the menu); the customer app reads the running ones from /app/deals.
 */
class DealController extends Controller
{
    public function __construct(
        private DealService $deals,
        private TenantContext $tenant,
        private RestaurantResolver $restaurants,
    ) {
    }

    // ---------------------------------------------------------------- staff

    public function index(Request $request)
    {
        $restaurant = $request->user()->restaurant;
        $today = $this->deals->today($restaurant);

        $deals = Deal::with('items.product')->orderBy('display_order')->orderByDesc('id')->get()
            ->map(fn (Deal $d) => $this->present($d, $today));

        return ApiResponse::success($deals);
    }

    public function show(Request $request, int $deal)
    {
        $deal = Deal::with('items.product')->findOrFail($deal);

        return ApiResponse::success($this->present($deal, $this->deals->today($request->user()->restaurant)));
    }

    public function store(Request $request)
    {
        $restaurant = $request->user()->restaurant;
        $data = $this->validated($request);

        $deal = DB::transaction(function () use ($restaurant, $data) {
            $deal = Deal::create(collect($data)->except('items')->all() + ['restaurant_id' => $restaurant->id]);
            $this->syncItems($deal, $data['items']);

            return $deal;
        });

        $this->audit($request, 'deal.created', $deal, ['new' => $data]);

        return ApiResponse::success($this->present($deal->load('items.product'), $this->deals->today($restaurant)), 201);
    }

    public function update(Request $request, int $deal)
    {
        $deal = Deal::findOrFail($deal);
        $data = $this->validated($request, partial: true);

        DB::transaction(function () use ($deal, $data) {
            $deal->update(collect($data)->except('items')->all());
            if (isset($data['items'])) {
                $this->syncItems($deal, $data['items']);
            }
        });

        $this->audit($request, 'deal.updated', $deal, ['new' => $data]);

        return ApiResponse::success($this->present($deal->fresh()->load('items.product'), $this->deals->today($request->user()->restaurant)));
    }

    public function destroy(Request $request, int $deal)
    {
        $deal = Deal::findOrFail($deal);
        $deal->delete();
        $this->audit($request, 'deal.deleted', $deal, []);

        return ApiResponse::success(['deleted' => true]);
    }

    /** POST /deals/image — a photo for a deal; returns the URL to save on it. */
    public function uploadImage(Request $request)
    {
        $request->validate(['file' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:4096']]);

        $restaurant = $request->user()->restaurant;
        $file = $request->file('file');
        $path = $file->storeAs(
            "restaurants/{$restaurant->id}/deals",
            Str::lower(Str::random(14)).'.'.$file->extension(),
            'public'
        );

        return ApiResponse::success(['url' => CustomerAppBranding::url('/storage/'.$path)], 201);
    }

    // --------------------------------------------------------------- the app

    /** GET /api/v1/app/deals?branch=id — public: deals running today, with what's in each. */
    public function publicIndex(Request $request)
    {
        $restaurant = $this->restaurants->resolve($request);
        if (! $restaurant) {
            return ApiResponse::error('NOT_FOUND', 'No restaurant is configured for this server URL.', 404);
        }
        $this->tenant->reset()->setRestaurantId($restaurant->id);

        $branch = $request->query('branch')
            ? Branch::where('restaurant_id', $restaurant->id)->find($request->query('branch'))
            : null;

        $deals = $this->deals->runningDeals($restaurant, $branch)
            ->filter(fn (Deal $d) => $d->is_available)
            ->map(fn (Deal $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'description' => $d->description,
                'image' => CustomerAppBranding::url($d->image),
                'price' => (float) $d->price,
                'original_price' => $d->original_price,
                'savings' => max(0, round($d->original_price - (float) $d->price, 2)),
                'ends_on' => $d->ends_on?->toDateString(),
                'items' => $d->items->map(fn ($i) => ['product_id' => $i->product_id, 'name' => $i->product?->name, 'quantity' => $i->quantity])->values(),
            ])->values();

        return ApiResponse::success(['branch_id' => $branch?->id, 'deals' => $deals]);
    }

    // -------------------------------------------------------------- helpers

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';
        $data = $request->validate([
            'name' => [$req, 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'image' => ['nullable', 'string', 'max:2048'],
            'price' => [$req, 'numeric', 'min:0'],
            'status' => ['nullable', 'in:ACTIVE,INACTIVE'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'display_order' => ['nullable', 'integer', 'min:0'],
            'items' => [$req, 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:50'],
        ]);

        if (isset($data['items'])) {
            $products = Product::with('modifierGroups')->whereIn('id', array_column($data['items'], 'product_id'))->get()->keyBy('id');
            foreach ($data['items'] as $i => $item) {
                $product = $products->get($item['product_id']);
                if (! $product) {
                    throw ValidationException::withMessages(["items.$i.product_id" => 'That product does not exist.']);
                }
                // A combo item can't ask the customer to pick options.
                if ($product->modifierGroups->contains(fn ($g) => $g->is_required || $g->min_selections > 0)) {
                    throw ValidationException::withMessages(["items.$i.product_id" => "{$product->name} has options the customer must choose, so it can't be part of a deal."]);
                }
            }
        }

        $data['status'] = $data['status'] ?? ($partial ? null : 'ACTIVE');
        if ($data['status'] === null) {
            unset($data['status']);
        }

        return $data;
    }

    private function syncItems(Deal $deal, array $items): void
    {
        $deal->items()->delete();
        foreach ($items as $item) {
            $deal->items()->create([
                'restaurant_id' => $deal->restaurant_id,
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity'],
            ]);
        }
    }

    private function present(Deal $deal, string $today): array
    {
        $original = $deal->items->sum(fn ($i) => (float) ($i->product?->base_price ?? 0) * $i->quantity);

        return [
            'id' => $deal->id,
            'name' => $deal->name,
            'description' => $deal->description,
            'image' => $deal->image,
            'price' => (float) $deal->price,
            'original_price' => round($original, 2),
            'status' => $deal->status,
            'starts_on' => $deal->starts_on?->toDateString(),
            'ends_on' => $deal->ends_on?->toDateString(),
            'display_order' => (int) $deal->display_order,
            'is_running' => $deal->isRunningOn($today),
            'state' => $this->state($deal, $today),
            'items' => $deal->items->map(fn ($i) => [
                'product_id' => $i->product_id,
                'name' => $i->product?->name,
                'quantity' => $i->quantity,
                'price' => (float) ($i->product?->base_price ?? 0),
            ])->values(),
        ];
    }

    /** Why a deal is or isn't live, in one word for the admin list. */
    private function state(Deal $deal, string $today): string
    {
        if ($deal->status !== 'ACTIVE') {
            return 'PAUSED';
        }
        if ($deal->starts_on && $today < $deal->starts_on->toDateString()) {
            return 'SCHEDULED';
        }
        if ($deal->ends_on && $today > $deal->ends_on->toDateString()) {
            return 'ENDED';
        }

        return 'LIVE';
    }

    private function audit(Request $request, string $action, Deal $deal, array $changes): void
    {
        AuditLog::create([
            'restaurant_id' => $deal->restaurant_id,
            'user_id' => $request->user()->id,
            'action' => $action,
            'subject_type' => Deal::class,
            'subject_id' => $deal->id,
            'changes' => $changes,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);
    }
}
