<?php

declare(strict_types=1);

namespace App\Enum;

enum GdprDeletionStatus: string
{
    case Requested = 'requested';
    case Processing = 'processing';
    case Completed = 'completed';
}
