<?php

namespace App\Enums;

enum DeliveryZoneType: string
{
    case RADIUS = 'RADIUS';
    case POLYGON = 'POLYGON';
}
