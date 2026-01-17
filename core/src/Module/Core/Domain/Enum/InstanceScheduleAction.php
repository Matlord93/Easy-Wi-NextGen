<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Enum;

enum InstanceScheduleAction: string
{
    case Start = 'start';
    case Stop = 'stop';
    case Restart = 'restart';
    case Update = 'update';
}
