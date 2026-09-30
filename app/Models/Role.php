<?php

namespace App\Models;

use App\Enums\RoleScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Deliberately not tenant-scoped via the automatic BelongsToTenant trait:
 * system role templates have restaurant_id = null and must remain visible
 * to every restaurant, alongside that restaurant's own custom roles. Use
 * the availableTo() scope below wherever roles are listed for a restaurant.
 */
class Role extends Model
{
    protected $fillable = ['restaurant_id', 'name', 'slug', 'is_system', 'scope'];

    protected $casts = [
        'is_system' => 'boolean',
        'scope' => RoleScope::class,
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_roles');
    }

    public function isRestaurantWide(): bool
    {
        return $this->scope === RoleScope::RESTAURANT;
    }

    /** System templates (restaurant_id null) plus this restaurant's own custom roles. */
    public function scopeAvailableTo(Builder $query, int $restaurantId): Builder
    {
        return $query->where(function (Builder $q) use ($restaurantId) {
            $q->whereNull('restaurant_id')->orWhere('restaurant_id', $restaurantId);
        });
    }
}
