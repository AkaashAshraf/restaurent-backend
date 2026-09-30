<?php

namespace App\Enums;

/**
 * Shared ACTIVE/INACTIVE status for menu entities (Category, Product,
 * Modifier) — kept as one enum rather than three identical ones.
 */
enum MenuItemStatus: string
{
    case ACTIVE = 'ACTIVE';
    case INACTIVE = 'INACTIVE';
}
