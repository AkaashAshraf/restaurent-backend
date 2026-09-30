<?php

namespace App\Models;

use App\Enums\UserStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Deliberately does NOT use the automatic BelongsToTenant global scope.
 *
 * Authenticating a user (looking them up by email during login) has to
 * happen before any tenant context exists — that's exactly what
 * establishes the tenant context in the first place. A global scope here
 * would make login itself impossible to satisfy without a chicken-and-egg
 * problem, or would force every auth lookup to bypass tenant scoping,
 * which defeats the purpose of a scope developers can't forget.
 *
 * Instead, every place that lists/manages users for a restaurant (i.e.
 * everything except the login lookup) must go through the `ofRestaurant`
 * scope below, which is the single, centralized place that filter lives.
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'restaurant_id', 'name', 'email', 'phone', 'password', 'is_super_admin', 'status',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'status' => UserStatus::class,
        ];
    }

    public function scopeOfRestaurant(Builder $query, int $restaurantId): Builder
    {
        return $query->where('restaurant_id', $restaurantId);
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles');
    }

    public function branchAssignments(): HasMany
    {
        return $this->hasMany(UserBranch::class);
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'user_branches');
    }

    /** Registered push targets — see PushChannel and DeviceTokenController. */
    public function deviceTokens(): HasMany
    {
        return $this->hasMany(DeviceToken::class);
    }

    /** This user's mail/sms/push opt-ins — see NotificationPreferenceService. */
    public function notificationPreferences(): HasMany
    {
        return $this->hasMany(NotificationPreference::class);
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::ACTIVE;
    }

    /** Restaurant-scope role (Owner/Admin/Manager) grants access to every branch. */
    public function hasRestaurantWideAccess(): bool
    {
        return $this->roles->contains(fn (Role $role) => $role->isRestaurantWide());
    }

    public function accessibleBranchIds(): ?array
    {
        if ($this->hasRestaurantWideAccess()) {
            return null; // null = all branches
        }

        return $this->branchAssignments()->pluck('branch_id')->all();
    }

    public function canAccessBranch(int $branchId): bool
    {
        if ($this->hasRestaurantWideAccess()) {
            return true;
        }

        return $this->branchAssignments()->where('branch_id', $branchId)->exists();
    }

    public function hasPermission(string $key): bool
    {
        if ($this->is_super_admin) {
            return true;
        }

        return $this->roles->loadMissing('permissions')
            ->pluck('permissions')
            ->flatten()
            ->pluck('key')
            ->contains($key);
    }

    /** Used to scope a rider's own view of orders, and to gate who can be assigned as one. */
    public function hasRoleSlug(string $slug): bool
    {
        return $this->roles->contains(fn (Role $role) => $role->slug === $slug);
    }
}
