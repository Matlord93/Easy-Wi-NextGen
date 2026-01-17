<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Enum;

enum InstanceUpdatePolicy: string
{
    case Manual = 'manual';
    case Auto = 'auto';
}
