<?php

declare(strict_types=1);

namespace App\Enum;

enum InstanceUpdatePolicy: string
{
    case Manual = 'manual';
    case Auto = 'auto';
}
