<?php

namespace App\Models;

use App\Enums\RestaurantStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;

class Restaurant extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'legal_name', 'slug', 'logo', 'description', 'phone', 'email',
        'website', 'address', 'city', 'country', 'currency', 'timezone',
        'status', 'theme', 'app_branding',
    ];

    protected $casts = [
        'theme' => 'array',
        'app_branding' => 'array',
        'status' => RestaurantStatus::class,
    ];

    public function settings(): HasOne
    {
        return $this->hasOne(RestaurantSetting::class);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function activeSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)
            ->where('status', 'ACTIVE')
            ->latest('start_date');
    }

    public function featureOverrides(): HasMany
    {
        return $this->hasMany(RestaurantFeature::class);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(RestaurantDomain::class);
    }

    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function modifierGroups(): HasMany
    {
        return $this->hasMany(ModifierGroup::class);
    }

    public function tables(): HasMany
    {
        return $this->hasMany(Table::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** app_key is never mass-assigned — only CustomerAppController sets it. */
    protected $hidden = ['app_key'];

    public function isOperational(): bool
    {
        return $this->status === RestaurantStatus::ACTIVE;
    }
}
