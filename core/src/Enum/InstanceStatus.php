<?php

declare(strict_types=1);

namespace App\Enum;

enum InstanceStatus: string
{
    case Provisioning = 'provisioning';
    case Running = 'running';
    case Stopped = 'stopped';
    case Error = 'error';
}
