<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup\Adapter;

final class GameBackupAdapter implements BackupModuleAdapterInterface
{
    public function module(): string { return 'game'; }
    public function snapshot(string $resourceId): BackupSnapshot { return new BackupSnapshot($resourceId, 'game-backup.tar'); }
    public function restore(string $resourceId, string $archivePath, bool $dryRun = false): RestoreReport { return new RestoreReport($dryRun, true, 'Game restore delegated to module worker.'); }
}
