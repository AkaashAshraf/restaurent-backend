<?php

namespace App\Enums;

enum TableStatus: string
{
    case AVAILABLE = 'AVAILABLE';
    case OCCUPIED = 'OCCUPIED';
    case RESERVED = 'RESERVED';
    case UNAVAILABLE = 'UNAVAILABLE';

    public function canBeAssignedToNewOrder(): bool
    {
        return $this === self::AVAILABLE || $this === self::RESERVED;
    }
}
