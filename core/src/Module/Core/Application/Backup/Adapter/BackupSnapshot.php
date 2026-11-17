<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup\Adapter;

use App\Module\Core\Application\Backup\BackupTemporaryDirectory;

final class BackupSnapshot
{
    private bool $temporary = false;

    public function __construct(
        private readonly string $sourcePath,
        private readonly string $archiveName,
    ) {
    }

    public static function temporaryDirectory(string $path, string $archiveName): self
    {
        if (!BackupTemporaryDirectory::isOwned($path, 'easywi-backup-snapshot-')
            || ((int) fileperms($path) & 0077) !== 0) {
            throw new \InvalidArgumentException('Temporary backup snapshots require a private owned staging directory.');
        }

        $snapshot = new self($path, $archiveName);
        $snapshot->temporary = true;

        return $snapshot;
    }

    public function cleanup(): void
    {
        if ($this->temporary) {
            BackupTemporaryDirectory::remove($this->sourcePath, 'easywi-backup-snapshot-');
        }
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
