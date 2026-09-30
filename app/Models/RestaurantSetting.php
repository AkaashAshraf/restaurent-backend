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
    ];

    protected $casts = [
        'order_types' => 'array',
        'tax_enabled' => 'boolean',
        'tax_inclusive' => 'boolean',
        'delivery_enabled' => 'boolean',
        'order_number_daily_reset' => 'boolean',
        'min_order_amount' => 'decimal:2',
        'tax_percentage' => 'decimal:2',
        'delivery_fee' => 'decimal:2',
        'free_delivery_threshold' => 'decimal:2',
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }
}
