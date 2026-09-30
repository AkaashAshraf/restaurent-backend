<?php

namespace App\Models;

use App\Enums\MenuItemStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Modifier extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant;

    protected $fillable = [
        'restaurant_id', 'modifier_group_id', 'name', 'price_adjustment',
        'is_default', 'display_order', 'status',
    ];

    protected $casts = [
        'status' => MenuItemStatus::class,
        'price_adjustment' => 'decimal:2',
        'is_default' => 'boolean',
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function modifierGroup(): BelongsTo
    {
        return $this->belongsTo(ModifierGroup::class);
    }
}
