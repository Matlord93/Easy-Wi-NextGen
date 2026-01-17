<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Enum;

enum TicketCategory: string
{
    case General = 'general';
    case Billing = 'billing';
    case Technical = 'technical';
    case Abuse = 'abuse';
}
