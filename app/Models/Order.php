<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'restaurant_id', 'branch_id', 'table_id', 'customer_id', 'placed_by_user_id',
        'assigned_rider_id', 'order_number', 'order_type', 'status', 'subtotal', 'tax_amount',
        'delivery_fee', 'discount_amount', 'total_amount', 'delivery_address',
        'delivery_latitude', 'delivery_longitude', 'delivery_zone_id', 'coupon_id', 'notes',
    ];

    protected $casts = [
        'order_type' => OrderType::class,
        'status' => OrderStatus::class,
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'delivery_fee' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'delivery_latitude' => 'float',
        'delivery_longitude' => 'float',
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(Table::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function placedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'placed_by_user_id');
    }

    public function assignedRider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_rider_id');
    }

    public function deliveryZone(): BelongsTo
    {
        return $this->belongsTo(DeliveryZone::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** The kitchen's view of this order: one ticket per round of items (see KitchenTicket). */
    public function kitchenTickets(): HasMany
    {
        return $this->hasMany(KitchenTicket::class)->orderBy('sequence');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** Sum of this order's settled payments — PENDING/FAILED/REFUNDED never count. */
    public function paidTotal(): float
    {
        return (float) $this->payments()->where('status', PaymentStatus::PAID->value)->sum('amount');
    }

    public function outstandingBalance(): float
    {
        return max(0.0, round((float) $this->total_amount - $this->paidTotal(), 2));
    }

    public function isFullyPaid(): bool
    {
        return $this->outstandingBalance() <= 0.0;
    }
}
