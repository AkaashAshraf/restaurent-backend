<?php

namespace App\Models;

use App\Enums\KitchenTicketStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One round of items on an order, as the kitchen sees it. Every order
 * starts with ticket 1 (sequence 1, the "main" ticket) holding its
 * original items. Items a waiter adds after the kitchen has accepted the
 * order go onto a new ticket (sequence 2, 3, ...), so the kitchen only
 * sees what's new — never food that was already cooked and served.
 *
 * The bill doesn't care about tickets: an order is still one order with
 * one set of items and one checkout. Tickets only track the kitchen's
 * work: New -> Accepted -> Cooking -> Ready -> Picked up.
 *
 * The main ticket moves in step with the order's own status (see
 * OrderService / KitchenTicketService); add-on tickets move on their own.
 */
class KitchenTicket extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'restaurant_id', 'branch_id', 'order_id', 'sequence', 'status',
        'ready_at', 'picked_up_at', 'picked_up_by_user_id',
    ];

    protected $casts = [
        'status' => KitchenTicketStatus::class,
        'sequence' => 'integer',
        'ready_at' => 'datetime',
        'picked_up_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function pickedUpBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'picked_up_by_user_id');
    }

    public function isMain(): bool
    {
        return $this->sequence === 1;
    }

    /**
     * Sets the status and keeps the ready/picked-up timestamps honest
     * (moving back out of READY clears ready_at, for instance). Does no
     * validation of its own — callers decide whether a move is allowed.
     */
    public function moveTo(KitchenTicketStatus $status, ?User $by = null): void
    {
        $attributes = ['status' => $status->value];

        if ($status === KitchenTicketStatus::READY) {
            $attributes['ready_at'] = now();
        } elseif ($status->rank() < KitchenTicketStatus::READY->rank()) {
            $attributes['ready_at'] = null;
        }

        if ($status === KitchenTicketStatus::PICKED_UP) {
            $attributes['picked_up_at'] = now();
            $attributes['picked_up_by_user_id'] = $by?->id;
        }

        $this->update($attributes);
    }
}
