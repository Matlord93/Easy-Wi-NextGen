<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup\Adapter;

final class DbBackupAdapter implements BackupModuleAdapterInterface
{
    #[\Override]
    public function module(): string
    {
        return 'db';
    }
    #[\Override]
    public function snapshot(string $resourceId): BackupSnapshot
    {
        return new BackupSnapshot($resourceId, 'database-backup.sql');
    }
    #[\Override]
    public function restore(string $resourceId, string $archivePath, bool $dryRun = false): RestoreReport
    {
        return new RestoreReport($dryRun, true, 'Database restore delegated to module worker.');
    }
}
