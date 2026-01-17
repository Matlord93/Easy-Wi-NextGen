<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Enum;

enum JobResultStatus: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
