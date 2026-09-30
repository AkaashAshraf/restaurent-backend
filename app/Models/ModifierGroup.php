<?php

namespace App\Models;

use App\Enums\ModifierSelectionType;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ModifierGroup extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant;

    protected $fillable = [
        'restaurant_id', 'name', 'selection_type', 'is_required',
        'min_selections', 'max_selections', 'display_order',
    ];

    protected $casts = [
        'selection_type' => ModifierSelectionType::class,
        'is_required' => 'boolean',
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function modifiers(): HasMany
    {
        return $this->hasMany(Modifier::class)->orderBy('display_order');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_modifier_groups');
    }
}
