<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Enum;

enum ShopOrderStatus: string
{
    case Pending = 'pending';
    case Provisioned = 'provisioned';
    case Failed = 'failed';
}
