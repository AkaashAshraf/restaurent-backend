<?php

namespace App\Services;

use App\Exceptions\OrderValidationException;
use App\Models\Branch;
use App\Models\Deal;
use App\Models\Restaurant;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Combo deals: which ones a customer can see at a branch, and how a deal in an
 * order turns into ordinary order lines.
 *
 * A deal order line is stored as its component products, each priced at its
 * share of the deal price, so the kitchen, the till, reports, returns and tax
 * all keep working on plain order items without knowing about deals.
 */
class DealService
{
    /** Today's date on the restaurant's own clock. */
    public function today(Restaurant $restaurant): string
    {
        $zone = in_array($restaurant->timezone, \DateTimeZone::listIdentifiers(), true) ? $restaurant->timezone : 'UTC';

        return now($zone)->toDateString();
    }

    /** Deals running today, each flagged with whether every item is on sale at this branch. */
    public function runningDeals(Restaurant $restaurant, ?Branch $branch): Collection
    {
        $today = $this->today($restaurant);

        return Deal::with(['items.product.branchOverrides'])
            ->where('status', 'ACTIVE')
            ->orderBy('display_order')->orderBy('name')
            ->get()
            ->filter(fn (Deal $deal) => $deal->isRunningOn($today) && $deal->items->isNotEmpty())
            ->map(function (Deal $deal) use ($branch) {
                $available = true;
                $original = 0.0;
                foreach ($deal->items as $item) {
                    $product = $item->product;
                    if (! $product || $product->trashed()) {
                        $available = false;
                        continue;
                    }
                    if ($branch && ! $product->isAvailableAtBranch($branch->id)) {
                        $available = false;
                    }
                    $original += ($branch ? $product->priceAtBranch($branch->id) : (float) $product->base_price) * $item->quantity;
                }

                $deal->setAttribute('is_available', $available);
                $deal->setAttribute('original_price', round($original, 2));

                return $deal;
            })
            ->values();
    }

    /**
     * Expands `deals` request lines ([{deal_id, quantity, notes?}]) into order line
     * items shaped like OrderService::buildLineItem()'s, with the deal price spread
     * over the components in proportion to what they cost on their own.
     */
    public function expand(Restaurant $restaurant, Branch $branch, array $dealLines): array
    {
        $today = $this->today($restaurant);
        $out = [];

        foreach ($dealLines as $line) {
            $deal = Deal::with('items.product.branchOverrides')->find($line['deal_id'] ?? 0);
            $qty = max(1, (int) ($line['quantity'] ?? 1));

            if (! $deal || ! $deal->isRunningOn($today) || $deal->items->isEmpty()) {
                throw new OrderValidationException('That deal is no longer available.');
            }

            $components = [];
            $base = 0.0;
            foreach ($deal->items as $item) {
                $product = $item->product;
                if (! $product || $product->trashed() || ! $product->isAvailableAtBranch($branch->id)) {
                    throw new OrderValidationException("{$deal->name} isn't available at this branch right now.");
                }
                $units = $item->quantity * $qty;
                $worth = $product->priceAtBranch($branch->id) * $units;
                $components[] = ['product' => $product, 'units' => $units, 'worth' => $worth];
                $base += $worth;
            }

            // Split the deal price by each item's own worth; the last line takes the rounding left over.
            $total = round((float) $deal->price * $qty, 2);
            $ref = (string) Str::uuid();
            $given = 0.0;
            $count = count($components);

            foreach ($components as $i => $c) {
                if ($i === $count - 1) {
                    $share = round($total - $given, 2);
                } else {
                    $share = $base > 0 ? round($total * $c['worth'] / $base, 2) : round($total / $count, 2);
                    $given += $share;
                }

                $out[] = [
                    'product' => $c['product'],
                    'quantity' => $c['units'],
                    'unit_price' => round($share / $c['units'], 2),
                    'modifiers_total' => 0.0,
                    'line_total' => $share,
                    'notes' => $line['notes'] ?? null,
                    'modifiers' => [],
                    'deal_id' => $deal->id,
                    'deal_name' => $deal->name,
                    'deal_ref' => $ref,
                ];
            }
        }

        return $out;
    }
}
