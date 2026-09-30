<?php

namespace App\Models;

use App\Enums\CouponType;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Restaurant-wide, not per-branch (unlike DeliveryZone) — a promo code is
 * a marketing decision made once for the whole restaurant, not a
 * per-location setting. See CouponService for how validity/limits are
 * enforced (twice: once optimistically when the code is submitted, once
 * more under a row lock immediately before the redemption is recorded).
 */
class Coupon extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'restaurant_id', 'code', 'type', 'value', 'min_order_amount', 'max_discount_amount',
        'usage_limit', 'per_customer_limit', 'valid_from', 'valid_until', 'is_active',
    ];

    protected $casts = [
        'type' => CouponType::class,
        'value' => 'decimal:2',
        'min_order_amount' => 'decimal:2',
        'max_discount_amount' => 'decimal:2',
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }

    public function isWithinValidityWindow(?Carbon $at = null): bool
    {
        $at ??= now();

        if ($this->valid_from && $at->lt($this->valid_from)) {
            return false;
        }

        if ($this->valid_until && $at->gt($this->valid_until)) {
            return false;
        }

        return true;
    }

    /** The discount this coupon produces for a given subtotal — never more than the subtotal itself. */
    public function discountFor(float $subtotal): float
    {
        $discount = $this->type === CouponType::PERCENTAGE
            ? $subtotal * ((float) $this->value / 100)
            : (float) $this->value;

        if ($this->type === CouponType::PERCENTAGE && $this->max_discount_amount !== null) {
            $discount = min($discount, (float) $this->max_discount_amount);
        }

        return round(min($discount, $subtotal), 2);
    }
}
