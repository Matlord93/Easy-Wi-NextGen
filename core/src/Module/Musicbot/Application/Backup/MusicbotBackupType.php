<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application\Backup;

enum MusicbotBackupType: string
{
    case Customer = 'customer';
    case Admin = 'admin';
    case Minimal = 'minimal';
    case Full = 'full';
}
