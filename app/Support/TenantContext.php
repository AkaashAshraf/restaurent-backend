<?php

namespace App\Support;

/**
 * Holds the current request's tenant scoping state. Bound as a singleton in
 * the service container and populated by the IdentifyTenant middleware.
 *
 * When restaurantId is set, the BelongsToTenant trait's global scope
 * automatically constrains every query on tenant models to that restaurant,
 * so individual controllers/services never have to remember to add
 * `where('restaurant_id', ...)` themselves.
 */
class TenantContext
{
    private ?int $restaurantId = null;

    /** null = access to all branches of the restaurant (restaurant-scope role). */
    private ?array $branchIds = null;

    private bool $isSuperAdmin = false;

    private bool $bypass = false;

    /**
     * Reset every field to its unauthenticated default. Bound as a
     * singleton, this context is normally rebuilt fresh by the OS/PHP
     * process boundary between requests (php-fpm, artisan serve). Under a
     * worker-reuse runtime (Octane) or in tests that share one container
     * across multiple simulated requests, nothing else guarantees that —
     * so IdentifyTenant calls this unconditionally at the top of every
     * request before deriving fresh state, rather than only overwriting
     * the fields relevant to the current user.
     */
    public function reset(): static
    {
        $this->restaurantId = null;
        $this->branchIds = null;
        $this->isSuperAdmin = false;
        $this->bypass = false;

        return $this;
    }

    public function setRestaurantId(?int $restaurantId): static
    {
        $this->restaurantId = $restaurantId;

        return $this;
    }

    public function restaurantId(): ?int
    {
        return $this->restaurantId;
    }

    public function setBranchIds(?array $branchIds): static
    {
        $this->branchIds = $branchIds;

        return $this;
    }

    /** null means "all branches of the current restaurant". */
    public function branchIds(): ?array
    {
        return $this->branchIds;
    }

    public function hasAllBranchAccess(): bool
    {
        return $this->branchIds === null;
    }

    public function canAccessBranch(int $branchId): bool
    {
        if ($this->hasAllBranchAccess()) {
            return true;
        }

        return in_array($branchId, $this->branchIds, true);
    }

    public function setSuperAdmin(bool $value): static
    {
        $this->isSuperAdmin = $value;

        return $this;
    }

    public function isSuperAdmin(): bool
    {
        return $this->isSuperAdmin;
    }

    /**
     * Explicitly bypass tenant scoping for the remainder of the request.
     * Used only by Super Admin platform-management code paths that must
     * legitimately operate across restaurants.
     */
    public function bypass(bool $value = true): static
    {
        $this->bypass = $value;

        return $this;
    }

    public function isBypassed(): bool
    {
        return $this->bypass;
    }
}
