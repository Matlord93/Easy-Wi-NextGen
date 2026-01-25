<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Enum;

enum ShopOrderType: string
{
    case New = 'new';
    case Extend = 'extend';
}
