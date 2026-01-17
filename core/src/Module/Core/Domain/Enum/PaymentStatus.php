<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Enum;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Refunded = 'refunded';
}
