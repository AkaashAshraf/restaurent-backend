<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Keyed to a user OR a customer, never a restaurant — deliberately does
 * not use BelongsToTenant. A staff member's or customer's own opt-in
 * choices aren't a tenant-owned resource the way a Coupon or Payment
 * is; they're exactly as owner-scoped as Laravel's own notifications
 * table (notifiable_id), which also ignores TenantContext entirely.
 * Exactly one of `user_id`/`customer_id` is set on any given row —
 * enforced by NotificationPreferenceService, not the schema (see the
 * Phase 10 migration's own docblock for why two separate unique indexes
 * were needed instead of one combined one).
 */
class NotificationPreference extends Model
{
    protected $fillable = ['user_id', 'customer_id', 'event_key', 'channel', 'enabled'];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
