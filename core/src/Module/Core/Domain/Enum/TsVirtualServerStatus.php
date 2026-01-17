<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Enum;

enum TsVirtualServerStatus: string
{
    case Planned = 'planned';
    case Active = 'active';
    case Stopped = 'stopped';
    case Error = 'error';
}
