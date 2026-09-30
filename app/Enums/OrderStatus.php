<?php

namespace App\Enums;

enum OrderStatus: string
{
    case PENDING = 'PENDING';
    case CONFIRMED = 'CONFIRMED';
    case PREPARING = 'PREPARING';
    case READY = 'READY';
    // Delivery-only step between READY and COMPLETED — the rider has
    // picked the order up but it hasn't reached the customer yet. Never
    // valid for DINE_IN/TAKEAWAY, which go straight from READY to
    // COMPLETED (see sequenceFor()).
    case OUT_FOR_DELIVERY = 'OUT_FOR_DELIVERY';
    case COMPLETED = 'COMPLETED';
    case CANCELLED = 'CANCELLED';

    /**
     * The order lifecycle is linear (PENDING -> ... -> COMPLETED), with
     * CANCELLED reachable from anywhere before the order is actually
     * fulfilled. Once COMPLETED or CANCELLED, an order is terminal — no
     * further status changes, refunds/corrections are a payments concern,
     * not a status transition. The linear sequence itself depends on the
     * order type: only a DELIVERY order passes through OUT_FOR_DELIVERY.
     */
    public function canTransitionTo(self $next, OrderType $orderType): bool
    {
        if ($this->isTerminal()) {
            return false;
        }

        if ($next === self::CANCELLED) {
            return true;
        }

        $sequence = self::sequenceFor($orderType);
        $currentIndex = array_search($this, $sequence, true);
        $nextIndex = array_search($next, $sequence, true);

        // Only the immediate next step forward is allowed — no skipping
        // straight from PENDING to READY, and no moving backward.
        return $currentIndex !== false && $nextIndex !== false && $nextIndex === $currentIndex + 1;
    }

    /**
     * One step back, for the kitchen's "undo". Only possible while the
     * order is still in the kitchen's hands — up to and including READY.
     * Once it has been picked up (OUT_FOR_DELIVERY, COMPLETED) or
     * cancelled there's nothing to undo, and PENDING has nothing before
     * it, so those return null.
     */
    public function previousKitchenStep(): ?self
    {
        return match ($this) {
            self::CONFIRMED => self::PENDING,
            self::PREPARING => self::CONFIRMED,
            self::READY => self::PREPARING,
            default => null,
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::COMPLETED || $this === self::CANCELLED;
    }

    /** Does this status still hold the table (if any) / block a new order on it? */
    public function occupiesTable(): bool
    {
        return ! $this->isTerminal();
    }

    /** @return self[] */
    private static function sequenceFor(OrderType $orderType): array
    {
        $base = [self::PENDING, self::CONFIRMED, self::PREPARING, self::READY];

        return $orderType === OrderType::DELIVERY
            ? [...$base, self::OUT_FOR_DELIVERY, self::COMPLETED]
            : [...$base, self::COMPLETED];
    }
}
