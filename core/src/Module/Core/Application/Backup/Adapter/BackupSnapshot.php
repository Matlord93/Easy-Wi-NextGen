<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup\Adapter;

final class BackupSnapshot
{
    public function __construct(
        private readonly string $sourcePath,
        private readonly string $archiveName,
    ) {
    }

    public function sourcePath(): string
    {
        return $this->sourcePath;
    }

    public function archiveName(): string
    {
        return $this->archiveName;
    }
}
