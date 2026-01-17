<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Enum;

enum TicketStatus: string
{
    case Open = 'open';
    case Pending = 'pending';
    case Resolved = 'resolved';
    case Closed = 'closed';
}
