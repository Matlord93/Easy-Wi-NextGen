<?php

declare(strict_types=1);

namespace App\Enum;

enum InstanceDiskState: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case OverLimit = 'over_limit';
    case HardBlock = 'hard_block';
}
