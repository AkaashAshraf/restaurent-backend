<?php

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;
use App\Support\TenantContext;

/**
 * Apply to any Eloquent model that carries a restaurant_id column. Adds a
 * global scope that automatically constrains queries to the current tenant
 * and auto-fills restaurant_id on create when it isn't explicitly set.
 *
 * This is the single mechanism the platform relies on for tenant isolation
 * (see spec #13) — developers should never need to add
 * `where('restaurant_id', ...)` by hand.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model) {
            if ($model->{$model->getTenantColumn()} === null) {
                $restaurantId = app(TenantContext::class)->restaurantId();

                if ($restaurantId !== null) {
                    $model->{$model->getTenantColumn()} = $restaurantId;
                }
            }
        });
    }

    public function getTenantColumn(): string
    {
        return 'restaurant_id';
    }

    public function getQualifiedTenantColumn(): string
    {
        return $this->getTable().'.'.$this->getTenantColumn();
    }

    /**
     * Escape hatch for legitimate cross-tenant reads (Super Admin panel).
     * Prefer TenantContext::bypass() at the request level over this per-query
     * call where possible, so intent is visible at the boundary.
     */
    public static function withoutTenantScope()
    {
        return static::withoutGlobalScope(TenantScope::class);
    }
}
