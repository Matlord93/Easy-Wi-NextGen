<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Enum;

enum BackupDestinationType: string
{
    case Local = 'local';
    case Nfs = 'nfs';
    case Smb = 'smb';
    case Webdav = 'webdav';
    case Nextcloud = 'nextcloud';
}
