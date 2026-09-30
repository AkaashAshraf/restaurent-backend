<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class CustomerAddress extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'restaurant_id', 'customer_id', 'label', 'full_address', 'latitude',
        'longitude', 'building', 'floor', 'delivery_instructions', 'is_default',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'is_default' => 'boolean',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
