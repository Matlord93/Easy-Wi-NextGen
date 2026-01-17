<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Enum;

enum Ts3InstanceStatus: string
{
    case Provisioning = 'provisioning';
    case Running = 'running';
    case Stopped = 'stopped';
    case Error = 'error';
}
