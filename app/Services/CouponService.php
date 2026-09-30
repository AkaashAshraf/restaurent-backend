<?php

namespace App\Services;

use App\Exceptions\CouponException;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\CouponRedemption;
use App\Models\Order;
use App\Models\Restaurant;

/**
 * Coupon validity/limits are checked twice by design:
 *
 *   1. resolve() — right after the code is submitted, optimistically
 *      (no row lock), so a bad or exhausted code fails fast with a clear
 *      message before any order pricing work happens.
 *   2. redeem() — again, under a locked row, from inside OrderService's
 *      DB transaction, immediately before the redemption is recorded.
 *      This closes the race two concurrent requests could otherwise
 *      exploit against the very last remaining use of a limited coupon;
 *      resolve() alone can't prevent that (the same "then re-check
 *      inside the transaction" shape used for delivery zones/table
 *      locking elsewhere in this codebase — just applied to a
 *      row-level counter instead of a status field).
 *
 * Coupon::where('code', ...) is safe to rely on for tenant isolation
 * here without an explicit restaurant_id filter — unlike Customer/User,
 * a Coupon is never looked up during Sanctum authentication, so
 * TenantContext is always already established by the time this runs.
 * The explicit restaurant_id filter below is added anyway: a promo code
 * is money, and Phase 6's Customer bug is a standing reminder not to
 * trust a global scope alone on anything that touches it twice.
 */
class CouponService
{
    public function resolve(Restaurant $restaurant, string $code, float $subtotal, ?Customer $customer): Coupon
    {
        $coupon = Coupon::where('restaurant_id', $restaurant->id)
            ->where('code', $this->normalize($code))
            ->first();

        if (! $coupon) {
            throw new CouponException('This coupon code is not valid.');
        }

        $this->assertRedeemable($coupon, $subtotal, $customer);

        return $coupon;
    }

    /**
     * Re-validates a coupon under a row lock and records its redemption.
     * Must be called from inside the caller's own DB transaction, after
     * the order it belongs to already exists.
     */
    public function redeem(Coupon $coupon, Order $order, ?Customer $customer, float $discountAmount): CouponRedemption
    {
        $locked = Coupon::whereKey($coupon->id)->lockForUpdate()->first();

        $this->assertRedeemable($locked, (float) $order->subtotal, $customer);

        return CouponRedemption::create([
            'restaurant_id' => $order->restaurant_id,
            'coupon_id' => $locked->id,
            'order_id' => $order->id,
            'customer_id' => $customer?->id,
            'discount_amount' => $discountAmount,
        ]);
    }

    private function assertRedeemable(Coupon $coupon, float $subtotal, ?Customer $customer): void
    {
        if (! $coupon->is_active) {
            throw new CouponException('This coupon is no longer active.');
        }

        if (! $coupon->isWithinValidityWindow()) {
            throw new CouponException('This coupon is not valid at this time.');
        }

        if ($subtotal < (float) $coupon->min_order_amount) {
            throw new CouponException("This coupon requires a minimum order amount of {$coupon->min_order_amount}.");
        }

        if ($coupon->usage_limit !== null && $coupon->redemptions()->count() >= $coupon->usage_limit) {
            throw new CouponException('This coupon has reached its usage limit.');
        }

        if ($customer && $coupon->per_customer_limit !== null) {
            $usedByCustomer = $coupon->redemptions()->where('customer_id', $customer->id)->count();

            if ($usedByCustomer >= $coupon->per_customer_limit) {
                throw new CouponException('You have already used this coupon the maximum number of times.');
            }
        }
    }

    private function normalize(string $code): string
    {
        return strtoupper(trim($code));
    }
}
