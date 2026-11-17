<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application\Backup;

use App\Module\Core\Application\Backup\Adapter\BackupModuleAdapterInterface;
use App\Module\Core\Application\Backup\Adapter\BackupSnapshot;
use App\Module\Core\Application\Backup\Adapter\RestoreReport;
use App\Module\Core\Application\Backup\BackupTemporaryDirectory;
use App\Module\Core\Application\Backup\BackupTarReader;

/** Administrator-managed disaster recovery, including remote media. */
final class MusicbotFullBackupAdapter implements BackupModuleAdapterInterface
{
    public function __construct(private readonly MusicbotFullBackupOperations $operations, private readonly BackupTarReader $tar = new BackupTarReader())
    {
    }

    #[\Override]
    public function module(): string
    {
        return 'musicbot_full';
    }

    #[\Override]
    public function snapshot(string $resourceId): BackupSnapshot
    {
        $directory = BackupTemporaryDirectory::create('easywi-backup-snapshot-');
        try {
            $this->operations->snapshot($resourceId, $directory);

            return BackupSnapshot::temporaryDirectory($directory, 'musicbot-full-backup.tar');
        } catch (\Throwable $e) {
            BackupTemporaryDirectory::remove($directory, 'easywi-backup-snapshot-');
            throw $e;
        }
    }

    #[\Override]
    public function restore(string $resourceId, string $archivePath, bool $dryRun = false): RestoreReport
    {
        $directory = BackupTemporaryDirectory::create('easywi-musicbot-full-');
        try {
            if (str_contains($archivePath, "\0") || str_contains($archivePath, '://') || !is_file($archivePath)) {
                throw new \InvalidArgumentException('Full Musicbot archive must be a local regular file.');
            }
            // Extract only these two regular members into private staging, including payloads above 4 GiB.
            $this->tar->extractFiles($archivePath, $directory, ['full.json' => 100_663_296, 'assets.tar.gz' => null]);
            $json = file_get_contents($directory.'/full.json');
            if ($json === false) {
                throw new \RuntimeException('Cannot read full Musicbot metadata.');
            }

            return $this->operations->restore($resourceId, $json, $directory.'/assets.tar.gz', $dryRun);
        } catch (\Throwable $e) {
            return new RestoreReport($dryRun, false, 'Full Musicbot restore failed: '.$e->getMessage());
        } finally {
            BackupTemporaryDirectory::remove($directory, 'easywi-musicbot-full-');
        }
    }
}
