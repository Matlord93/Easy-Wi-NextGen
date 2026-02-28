<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup\Adapter;

final class VoiceBackupAdapter implements BackupModuleAdapterInterface
{
    public function module(): string { return 'voice'; }
    public function snapshot(string $resourceId): BackupSnapshot { return new BackupSnapshot($resourceId, 'voice-backup.tar'); }
    public function restore(string $resourceId, string $archivePath, bool $dryRun = false): RestoreReport { return new RestoreReport($dryRun, true, 'Voice restore delegated to module worker.'); }
}
