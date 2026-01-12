<?php

declare(strict_types=1);

namespace App\Enum;

enum JobResultStatus: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
