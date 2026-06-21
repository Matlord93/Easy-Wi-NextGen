<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Domain\Enum;

enum MusicbotInstanceStatus: string
{
    case Provisioning = 'provisioning';
    case Installed = 'installed';
    case Running = 'running';
    case Stopped = 'stopped';
    case Error = 'error';
}
