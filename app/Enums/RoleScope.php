<?php

namespace App\Enums;

enum RoleScope: string
{
    case RESTAURANT = 'RESTAURANT'; // all branches
    case BRANCH = 'BRANCH'; // only assigned branches
}
