<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Enum;

enum ConnectionPolicy: string
{
    case Private = 'private';
    case Public = 'public';
    case VpnOnly = 'vpn_only';
}
