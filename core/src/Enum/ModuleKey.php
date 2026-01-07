<?php

declare(strict_types=1);

namespace App\Enum;

enum ModuleKey: string
{
    case Web = 'web';
    case Mail = 'mail';
    case Dns = 'dns';
    case Game = 'game';
    case Ts = 'ts';
    case Billing = 'billing';
}
