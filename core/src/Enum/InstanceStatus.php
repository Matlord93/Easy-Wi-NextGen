<?php

declare(strict_types=1);

namespace App\Enum;

enum InstanceStatus: string
{
    case PendingSetup = 'pending_setup';
    case Provisioning = 'provisioning';
    case Running = 'running';
    case Stopped = 'stopped';
    case Suspended = 'suspended';
    case Error = 'error';
}
