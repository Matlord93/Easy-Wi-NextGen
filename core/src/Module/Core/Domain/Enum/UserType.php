<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Enum;

enum UserType: string
{
    case Admin = 'admin';
    case Superadmin = 'superadmin';
    case Reseller = 'reseller';
    case Customer = 'customer';

    public function isAdmin(): bool
    {
        return in_array($this, [self::Admin, self::Superadmin], true);
    }
}
