<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Enum;

enum BackupStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
