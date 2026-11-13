<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup\Adapter;

final class MailBackupAdapter implements BackupModuleAdapterInterface
{
    #[\Override]
    public function module(): string
    {
        return 'mail';
    }
    #[\Override]
    public function snapshot(string $resourceId): BackupSnapshot
    {
        return new BackupSnapshot($resourceId, 'mail-backup.tar');
    }
    #[\Override]
    public function restore(string $resourceId, string $archivePath, bool $dryRun = false): RestoreReport
    {
        return new RestoreReport($dryRun, true, 'Mail restore delegated to module worker.');
    }
}
