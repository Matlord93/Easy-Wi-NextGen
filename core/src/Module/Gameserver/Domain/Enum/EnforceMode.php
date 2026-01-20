<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Domain\Enum;

enum EnforceMode: string
{
    case EnforceByArgs = 'ENFORCE_BY_ARGS';
    case EnforceByConfig = 'ENFORCE_BY_CONFIG';
}
