<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Enum;

enum DeployStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
