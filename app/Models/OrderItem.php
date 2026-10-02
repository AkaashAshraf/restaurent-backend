<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItem extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'restaurant_id', 'order_id', 'kitchen_ticket_id', 'product_id', 'deal_id', 'deal_name', 'deal_ref', 'product_name', 'quantity',
        'returned_quantity', 'unit_price', 'modifiers_total', 'line_total', 'notes',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'modifiers_total' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function kitchenTicket(): BelongsTo
    {
        return $this->belongsTo(KitchenTicket::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function modifiers(): HasMany
    {
        return $this->hasMany(OrderItemModifier::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(OrderItemReturn::class);
    }

    public function remainingQuantity(): int
    {
        return max(0, $this->quantity - $this->returned_quantity);
    }
}
