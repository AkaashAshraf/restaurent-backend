<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A customer's rating of a completed order: food, rider (delivery) and the app. */
class OrderReview extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'restaurant_id', 'order_id', 'customer_id', 'branch_id', 'rider_id',
        'food_rating', 'food_review', 'rider_rating', 'rider_review', 'app_rating', 'app_review',
    ];

    protected $casts = [
        'food_rating' => 'integer',
        'rider_rating' => 'integer',
        'app_rating' => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function rider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rider_id');
    }
}
