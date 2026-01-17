<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Enum;

enum CreditNoteStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Applied = 'applied';
    case Voided = 'voided';
}
