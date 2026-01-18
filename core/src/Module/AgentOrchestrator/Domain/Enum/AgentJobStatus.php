<?php

declare(strict_types=1);

namespace App\Module\AgentOrchestrator\Domain\Enum;

enum AgentJobStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Success = 'success';
    case Failed = 'failed';
}
