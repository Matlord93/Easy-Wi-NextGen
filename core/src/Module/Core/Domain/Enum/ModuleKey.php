<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Enum;

enum ModuleKey: string
{
    case Web = 'web';
    case Mail = 'mail';
    case Dns = 'dns';
    case Game = 'game';
    case Ts = 'ts';
    case Ts6 = 'ts6';
    case TsVirtual = 'ts_virtual';
    case Billing = 'billing';
}
