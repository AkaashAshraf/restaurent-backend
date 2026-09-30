<?php

namespace App\Models;

use App\Enums\MenuItemStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant;

    protected $fillable = [
        'restaurant_id', 'category_id', 'name', 'slug', 'description', 'image',
        'base_price', 'preparation_time_minutes', 'display_order', 'status',
    ];

    protected $casts = [
        'status' => MenuItemStatus::class,
        'base_price' => 'decimal:2',
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function modifierGroups(): BelongsToMany
    {
        return $this->belongsToMany(ModifierGroup::class, 'product_modifier_groups')
            ->withPivot('display_order')
            ->withTimestamps()
            ->orderBy('product_modifier_groups.display_order');
    }

    public function branchOverrides(): HasMany
    {
        return $this->hasMany(BranchProduct::class);
    }

    /** Effective availability at a given branch: an explicit override, or the product's own status. */
    public function isAvailableAtBranch(int $branchId): bool
    {
        $override = $this->branchOverrides->firstWhere('branch_id', $branchId);

        if ($override) {
            return $override->is_available && $this->status === MenuItemStatus::ACTIVE;
        }

        return $this->status === MenuItemStatus::ACTIVE;
    }

    /** Effective price at a given branch: an explicit override, or the product's own base_price. */
    public function priceAtBranch(int $branchId): float
    {
        $override = $this->branchOverrides->firstWhere('branch_id', $branchId);

        return (float) ($override?->price_override ?? $this->base_price);
    }
}
