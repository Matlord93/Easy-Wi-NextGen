<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup\Adapter;

final class VoiceBackupAdapter implements BackupModuleAdapterInterface
{
    #[\Override]
    public function module(): string
    {
        return 'voice';
    }
    #[\Override]
    public function snapshot(string $resourceId): BackupSnapshot
    {
        return new BackupSnapshot($resourceId, 'voice-backup.tar');
    }
    #[\Override]
    public function restore(string $resourceId, string $archivePath, bool $dryRun = false): RestoreReport
    {
        return new RestoreReport($dryRun, true, 'Voice restore delegated to module worker.');
    }
}
