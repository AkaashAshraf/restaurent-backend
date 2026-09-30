<?php

namespace App\Enums;

/**
 * Where one kitchen ticket (one round of items on an order) is in the
 * kitchen. The first four values deliberately match OrderStatus's kitchen
 * steps, so the main ticket (sequence 1) and its order always speak the
 * same language. After READY there's one more step the order itself
 * doesn't have: PICKED_UP — the waiter has collected the food, and the
 * ticket leaves the kitchen screen for good.
 */
enum KitchenTicketStatus: string
{
    case PENDING = 'PENDING';
    case CONFIRMED = 'CONFIRMED';
    case PREPARING = 'PREPARING';
    case READY = 'READY';
    case PICKED_UP = 'PICKED_UP';
    case CANCELLED = 'CANCELLED';

    /** The one step forward from here, or null at the end of the line. */
    public function next(): ?self
    {
        return match ($this) {
            self::PENDING => self::CONFIRMED,
            self::CONFIRMED => self::PREPARING,
            self::PREPARING => self::READY,
            self::READY => self::PICKED_UP,
            default => null,
        };
    }

    /**
     * One step back, for the kitchen's undo. Only until pickup: a picked
     * up or cancelled ticket can't be moved back, and PENDING has nothing
     * before it.
     */
    public function previous(): ?self
    {
        return match ($this) {
            self::CONFIRMED => self::PENDING,
            self::PREPARING => self::CONFIRMED,
            self::READY => self::PREPARING,
            default => null,
        };
    }

    /** Still on the kitchen screen (not yet picked up or cancelled). */
    public function isInKitchen(): bool
    {
        return in_array($this, [self::PENDING, self::CONFIRMED, self::PREPARING, self::READY], true);
    }

    /** Ordering along the lifecycle — used to never move a ticket backwards by accident. */
    public function rank(): int
    {
        return match ($this) {
            self::PENDING => 0,
            self::CONFIRMED => 1,
            self::PREPARING => 2,
            self::READY => 3,
            self::PICKED_UP => 4,
            self::CANCELLED => 5,
        };
    }

    /**
     * The order status the main ticket's move corresponds to, or null for
     * PICKED_UP/CANCELLED (the order has no such kitchen step — picking up
     * the food leaves the order READY until checkout).
     */
    public function toOrderStatus(): ?OrderStatus
    {
        return match ($this) {
            self::PENDING => OrderStatus::PENDING,
            self::CONFIRMED => OrderStatus::CONFIRMED,
            self::PREPARING => OrderStatus::PREPARING,
            self::READY => OrderStatus::READY,
            default => null,
        };
    }
}
