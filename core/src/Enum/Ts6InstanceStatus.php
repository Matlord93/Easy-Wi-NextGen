<?php

declare(strict_types=1);

namespace App\Enum;

enum Ts6InstanceStatus: string
{
    case Planned = 'planned';
    case Provisioning = 'provisioning';
    case Running = 'running';
    case Stopped = 'stopped';
    case Error = 'error';
}
