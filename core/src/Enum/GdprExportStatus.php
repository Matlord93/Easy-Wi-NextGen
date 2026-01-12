<?php

declare(strict_types=1);

namespace App\Enum;

enum GdprExportStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Expired = 'expired';
}
