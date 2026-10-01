<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Deliberately does NOT use the automatic BelongsToTenant global scope —
 * same reasoning as `User` (see its docblock). Sanctum resolves a bearer
 * token's `tokenable` (i.e. looks this model up by id) as part of
 * *authenticating* the request, which happens before the `tenant`
 * middleware has run and established any TenantContext. `BelongsToTenant`
 * fails closed with no tenant context set (`whereRaw('1 = 0')`), which
 * silently made every customer token resolve to no user at all — caught
 * while building Phase 6's customer auth end-to-end, not by a unit test
 * on the model in isolation. Every place that looks up a Customer
 * outside of an already-authenticated Customer's own relations (e.g.
 * `$customer->addresses()`) must go through the `ofRestaurant` scope
 * below instead.
 */
/**
 * Phase 10 adds `Notifiable` — the same trait `User` has had since
 * Phase 1's scaffolding, unused until Phase 8 gave `User` a
 * notifications table to write to. Laravel's `notifications` table is
 * `notifiable`-polymorphic (`morphs('notifiable')`), so no schema
 * change was needed to let a `Customer` receive one too; only
 * `order.placed`/`order.status_changed` ever target a customer (see
 * `NotificationService`) — `order.rider_assigned`/`payment.received`
 * stay staff/rider-only, a customer has no reason to see either.
 */
class Customer extends Authenticatable
{
    use HasFactory, SoftDeletes, HasApiTokens, Notifiable;

    protected $fillable = [
        'restaurant_id', 'name', 'phone', 'email', 'google_id', 'apple_id', 'password', 'status',
    ];

    protected $hidden = ['password'];

    protected $casts = [
        'password' => 'hashed',
        'phone_verified_at' => 'datetime',
    ];

    public function scopeOfRestaurant(Builder $query, int $restaurantId): Builder
    {
        return $query->where('restaurant_id', $restaurantId);
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** Registered push targets — see PushChannel and DeviceTokenController. FK is `customer_id` by Eloquent's own naming convention. */
    public function deviceTokens(): HasMany
    {
        return $this->hasMany(DeviceToken::class);
    }

    /** This customer's mail/sms/push opt-ins — see NotificationPreferenceService. */
    public function notificationPreferences(): HasMany
    {
        return $this->hasMany(NotificationPreference::class);
    }
}
