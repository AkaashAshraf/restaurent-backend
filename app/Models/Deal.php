<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A combo: several menu items sold together at one fixed price. */
class Deal extends Model
{
    use SoftDeletes, BelongsToTenant;

    protected $fillable = [
        'restaurant_id', 'name', 'description', 'image', 'price', 'status',
        'starts_on', 'ends_on', 'display_order', 'notified_at', 'notified_count',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'starts_on' => 'date:Y-m-d',
        'ends_on' => 'date:Y-m-d',
        'notified_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(DealItem::class);
    }

    /** Switched on, and today (on the restaurant's clock) is inside its dates. */
    public function isRunningOn(string $today): bool
    {
        if ($this->status !== 'ACTIVE') {
            return false;
        }
        if ($this->starts_on && $today < $this->starts_on->toDateString()) {
            return false;
        }
        if ($this->ends_on && $today > $this->ends_on->toDateString()) {
            return false;
        }

        return true;
    }
}
