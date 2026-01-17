<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Enum;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case PastDue = 'past_due';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
}
