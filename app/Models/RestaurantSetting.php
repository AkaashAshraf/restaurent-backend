<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantSetting extends Model
{
    protected $fillable = [
        'restaurant_id', 'order_types', 'min_order_amount', 'default_prep_time_minutes',
        'tax_enabled', 'tax_percentage', 'tax_inclusive', 'delivery_enabled',
        'delivery_fee', 'free_delivery_threshold', 'order_number_scheme',
        'order_number_daily_reset', 'branch_selection_mode',
        'cash_tax_percentage', 'card_tax_percentage', 'kitchen_order_types', 'fbr_number',
    ];

    protected $casts = [
        'order_types' => 'array',
        'kitchen_order_types' => 'array',
        'tax_enabled' => 'boolean',
        'tax_inclusive' => 'boolean',
        'delivery_enabled' => 'boolean',
        'order_number_daily_reset' => 'boolean',
        'min_order_amount' => 'decimal:2',
        'tax_percentage' => 'decimal:2',
        'cash_tax_percentage' => 'decimal:2',
        'card_tax_percentage' => 'decimal:2',
        'delivery_fee' => 'decimal:2',
        'free_delivery_threshold' => 'decimal:2',
    ];

    /**
     * The tax rate (%) for a payment method. Before the customer has chosen
     * how to pay ($method = null) it's the default rate. CASH and CARD
     * each have their own rate when the restaurant set one; ONLINE counts as
     * a card payment. 0 when tax is switched off.
     */
    public function taxRateFor(?string $method): float
    {
        if (! $this->tax_enabled) {
            return 0.0;
        }

        $default = (float) $this->tax_percentage;

        return match ($method) {
            'CASH' => (float) ($this->cash_tax_percentage ?? $default),
            'CARD', 'ONLINE' => (float) ($this->card_tax_percentage ?? $default),
            default => (float) ($this->cash_tax_percentage ?? $default),
        };
    }

    /** Order types the kitchen receives. Everything unless the restaurant narrowed it. */
    public function kitchenOrderTypes(): array
    {
        $types = $this->kitchen_order_types;

        return is_array($types) && $types !== [] ? array_values($types) : ['DINE_IN', 'TAKEAWAY', 'DELIVERY'];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }
}
