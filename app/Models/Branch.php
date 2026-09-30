<?php

namespace App\Models;

use App\Enums\BranchStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant;

    protected $fillable = [
        'restaurant_id', 'name', 'branch_code', 'phone', 'email', 'address',
        'city', 'state', 'country', 'postal_code', 'latitude', 'longitude',
        'status', 'priority',
    ];

    protected $casts = [
        'status' => BranchStatus::class,
        'latitude' => 'float',
        'longitude' => 'float',
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function settings(): HasOne
    {
        return $this->hasOne(BranchSetting::class);
    }

    public function hours(): HasMany
    {
        return $this->hasMany(BranchHour::class);
    }

    public function userAssignments(): HasMany
    {
        return $this->hasMany(UserBranch::class);
    }

    public function productOverrides(): HasMany
    {
        return $this->hasMany(BranchProduct::class);
    }

    public function tables(): HasMany
    {
        return $this->hasMany(Table::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function deliveryZones(): HasMany
    {
        return $this->hasMany(DeliveryZone::class);
    }

    public function isOpenForNewOrders(): bool
    {
        return $this->status->acceptsNewOrders();
    }
}
