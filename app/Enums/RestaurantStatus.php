<?php

namespace App\Enums;

enum RestaurantStatus: string
{
    case ACTIVE = 'ACTIVE';
    case INACTIVE = 'INACTIVE';
    case SUSPENDED = 'SUSPENDED';
    case EXPIRED = 'EXPIRED';
}
