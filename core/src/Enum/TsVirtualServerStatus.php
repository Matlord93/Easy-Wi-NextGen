<?php

declare(strict_types=1);

namespace App\Enum;

enum TsVirtualServerStatus: string
{
    case Planned = 'planned';
    case Active = 'active';
    case Stopped = 'stopped';
    case Error = 'error';
}
