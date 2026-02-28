<?php

declare(strict_types=1);

namespace App\Module\HostingPanel\Domain\Enum;

enum JobStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Success = 'success';
    case Failed = 'failed';
    case Retry = 'retry';
    case Cancelled = 'cancelled';
}
