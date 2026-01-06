<?php

declare(strict_types=1);

namespace App\Enum;

enum UserType: string
{
    case Admin = 'admin';
    case Customer = 'customer';
}
