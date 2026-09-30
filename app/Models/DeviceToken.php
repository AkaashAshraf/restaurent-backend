<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Same "owner-scoped, not tenant-scoped, exactly one owner column set" reasoning as NotificationPreference. */
class DeviceToken extends Model
{
    protected $fillable = ['user_id', 'customer_id', 'token', 'platform'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
