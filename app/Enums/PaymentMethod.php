<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case CASH = 'CASH';
    case CARD = 'CARD';
    case ONLINE = 'ONLINE';

    /**
     * Only ONLINE needs the ONLINE_PAYMENTS plan feature — recording a
     * cash or card payment at the till is core POS bookkeeping, not a
     * paid add-on, so it's never gated.
     */
    public function requiresOnlinePaymentsFeature(): bool
    {
        return $this === self::ONLINE;
    }

    /** CASH/CARD settle the moment they're recorded; ONLINE starts PENDING. */
    public function settlesImmediately(): bool
    {
        return $this !== self::ONLINE;
    }
}
