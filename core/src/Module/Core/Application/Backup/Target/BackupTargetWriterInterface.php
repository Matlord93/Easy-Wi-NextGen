<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup\Target;

use App\Module\Core\Application\Backup\BackupStorageTarget;

interface BackupTargetWriterInterface
{
    public function supports(BackupStorageTarget $target): bool;

    public function write(BackupStorageTarget $target, string $archiveName, string $sourceFile): string;
}
