<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Enum;

enum MailJobType: string
{
    case ApplyDomain = 'mail.applyDomain';
    case RotateDkim = 'mail.rotateDkim';
    case CheckDns = 'mail.checkDns';
    case FlushQueue = 'mail.flushQueue';
    case RestartService = 'mail.restartService';
}
