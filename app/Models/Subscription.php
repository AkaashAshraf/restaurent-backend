<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    protected $fillable = [
        'restaurant_id', 'subscription_plan_id', 'start_date', 'expiry_date',
        'status', 'branch_limit_override', 'user_limit_override',
    ];

    protected $casts = [
        'start_date' => 'date',
        'expiry_date' => 'date',
        'status' => SubscriptionStatus::class,
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function featureOverrides(): HasMany
    {
        return $this->hasMany(SubscriptionFeature::class);
    }

    public function branchLimit(): ?int
    {
        return $this->branch_limit_override ?? $this->plan->branch_limit;
    }

    public function userLimit(): ?int
    {
        return $this->user_limit_override ?? $this->plan->user_limit;
    }

    public function isCurrentlyActive(): bool
    {
        if ($this->status !== SubscriptionStatus::ACTIVE) {
            return false;
        }

        return $this->expiry_date === null || $this->expiry_date->isFuture() || $this->expiry_date->isToday();
    }
}
