<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup\Adapter;

final class MailBackupAdapter implements BackupModuleAdapterInterface
{
    public function module(): string
    {
        return 'mail';
    }
    public function snapshot(string $resourceId): BackupSnapshot
    {
        return new BackupSnapshot($resourceId, 'mail-backup.tar');
    }
    public function restore(string $resourceId, string $archivePath, bool $dryRun = false): RestoreReport
    {
        return new RestoreReport($dryRun, true, 'Mail restore delegated to module worker.');
    }
}
