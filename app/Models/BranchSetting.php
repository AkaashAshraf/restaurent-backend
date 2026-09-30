<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BranchSetting extends Model
{
    protected $fillable = [
        'branch_id', 'restaurant_id', 'order_types', 'delivery_enabled',
        'min_order_amount', 'free_delivery_threshold', 'prep_time_minutes',
        'max_delivery_distance_km',
    ];

    protected $casts = [
        'order_types' => 'array',
        'delivery_enabled' => 'boolean',
        'min_order_amount' => 'decimal:2',
        'free_delivery_threshold' => 'decimal:2',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
