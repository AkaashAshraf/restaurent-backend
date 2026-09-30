<?php

namespace App\Enums;

enum BranchStatus: string
{
    case ACTIVE = 'ACTIVE';
    case INACTIVE = 'INACTIVE';
    case TEMPORARILY_CLOSED = 'TEMPORARILY_CLOSED';
    case PERMANENTLY_CLOSED = 'PERMANENTLY_CLOSED';

    public function acceptsNewOrders(): bool
    {
        return $this === self::ACTIVE;
    }
}
