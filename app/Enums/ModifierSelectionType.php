<?php

namespace App\Enums;

enum ModifierSelectionType: string
{
    case SINGLE = 'SINGLE';     // radio — e.g. Size
    case MULTIPLE = 'MULTIPLE'; // checkboxes — e.g. Toppings
}
