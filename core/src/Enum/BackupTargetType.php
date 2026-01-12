<?php

declare(strict_types=1);

namespace App\Enum;

enum BackupTargetType: string
{
    case Webspace = 'webspace';
    case Database = 'database';
    case Mailbox = 'mailbox';
    case Game = 'game';
    case Ts3 = 'ts3';
}
