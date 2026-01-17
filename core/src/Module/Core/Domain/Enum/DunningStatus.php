<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Enum;

enum DunningStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Resolved = 'resolved';
    case Cancelled = 'cancelled';
}
