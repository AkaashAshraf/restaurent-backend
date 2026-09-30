<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One "waiter returned N of this item, here's why" record. Deliberately
 * its own table rather than just a couple of columns on OrderItem — a
 * line can be returned more than once (e.g. 3 ordered, 1 sent back for
 * being cold, then later another sent back as wrong), and the reason
 * history matters for the same audit-trail reasons Payment does.
 * OrderItem.returned_quantity is a cached running total kept in step
 * with this table by OrderService::returnItem(), not the source of
 * truth — this table is.
 */
class OrderItemReturn extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'restaurant_id', 'branch_id', 'order_id', 'order_item_id',
        'quantity', 'amount', 'reason', 'returned_by_user_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function returnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by_user_id');
    }
}
