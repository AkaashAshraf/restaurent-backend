<?php

namespace App\Enums;

enum OrderType: string
{
    case DINE_IN = 'DINE_IN';
    case TAKEAWAY = 'TAKEAWAY';
    case DELIVERY = 'DELIVERY';

    /** The Feature key that must be enabled for a restaurant to accept this order type at all. */
    public function featureKey(): string
    {
        return $this->value;
    }
}
