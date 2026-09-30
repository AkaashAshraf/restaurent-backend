<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Order;
use App\Models\Restaurant;

/**
 * Generates the next order number per restaurant, honoring the two knobs
 * `RestaurantSetting` already exposed since Phase 1 (`order_number_scheme`
 * = RESTAURANT|BRANCH, `order_number_daily_reset`) but that nothing read
 * until now. Sequence is a simple count-based lookup guarded by a unique
 * (restaurant_id, order_number) DB constraint — good enough for the order
 * volume this MVP is built for; a genuinely concurrent-safe atomic counter
 * (e.g. a dedicated sequence row locked with SELECT ... FOR UPDATE) is a
 * hardening item for real production traffic, not needed to prove the
 * feature out.
 */
class OrderNumberService
{
    public function next(Restaurant $restaurant, Branch $branch): string
    {
        $settings = $restaurant->settings;
        $scheme = $settings->order_number_scheme ?? 'RESTAURANT';
        $dailyReset = (bool) ($settings->order_number_daily_reset ?? false);

        $query = Order::where('restaurant_id', $restaurant->id);

        if ($scheme === 'BRANCH') {
            $query->where('branch_id', $branch->id);
        }

        if ($dailyReset) {
            $query->whereDate('created_at', now()->toDateString());
        }

        $sequence = $query->count() + 1;
        $prefix = $scheme === 'BRANCH' ? $branch->branch_code : 'ORD';
        $datePart = $dailyReset ? now()->format('Ymd').'-' : '';

        return sprintf('%s-%s%04d', $prefix, $datePart, $sequence);
    }
}
