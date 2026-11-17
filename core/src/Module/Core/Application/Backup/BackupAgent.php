<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup;

use App\Module\Core\Application\Backup\Adapter\RestoreReport;

final class BackupAgent
{
    public function __construct(
        private readonly BackupTargetValidator $targetValidator,
        private readonly BackupTargetWriterRegistry $targetWriterRegistry,
        private readonly BackupAdapterRegistry $adapterRegistry,
        private readonly ?BackupArchiveInventory $inventory = null,
        private readonly ?BackupOperationHeartbeat $heartbeat = null,
    ) {
    }

    public function run(BackupPlan $plan, ?string $idempotencyKey = null): BackupRun
    {
        $this->targetValidator->validate($plan->target());
        $this->inventory?->assertReady();
        $adapter = $this->adapterRegistry->forModule($plan->module());
        $snapshot = $adapter->snapshot($plan->resourceId());
        $runId = $idempotencyKey !== null && $idempotencyKey !== '' ? $idempotencyKey : bin2hex(random_bytes(12));
        $workspace = null;
        try {
            $workspace = BackupTemporaryDirectory::create();
            $codec = new BackupArchiveCodec($this->heartbeat);
            $identity = substr(hash('sha256', json_encode([$plan->id(), $runId], JSON_THROW_ON_ERROR)), 0, 32);
            $archiveStem = self::filenameComponent(pathinfo($snapshot->archiveName(), PATHINFO_FILENAME));
            $tempArchive = $workspace.'/'.self::filenameComponent($plan->id()).'-'.$archiveStem.'-'.$identity.'.tar';
            // Web restore still uses PharData, whose individual-member limit is below 4 GiB.
            $this->archivePath($snapshot->sourcePath(), $tempArchive, $plan->module() === 'web' ? 4294966784 : null);
            $finalArchivePath = $tempArchive;
            if (strtolower(self::configText($plan->options()['compression'] ?? 'none')) === 'gzip') {
                $finalArchivePath = $tempArchive.'.gz';
                $codec->gzip($tempArchive, $finalArchivePath);
            }
            if (($plan->options()['encrypt'] ?? false) === true) {
                $encryptedPath = $finalArchivePath.'.enc';
                $codec->encrypt($finalArchivePath, $encryptedPath, self::encryptionKey($plan));
                $finalArchivePath = $encryptedPath;
            }
            $checksum = BackupFileChecksum::sha256($finalArchivePath, $this->heartbeat);
            $size = filesize($finalArchivePath);
            if ($size === false) {
                throw new \RuntimeException('Unable to inspect completed backup archive.');
            }
            $destination = $this->targetWriterRegistry->write($plan->target(), basename($finalArchivePath), $finalArchivePath);
            $this->heartbeat?->pulse(true);

            $run = new BackupRun($runId, $plan->id(), 'succeeded', $destination, $size, $checksum);
            $this->inventory?->record($plan, $run);

            return $run;
        } finally {
            if ($workspace !== null) {
                BackupTemporaryDirectory::remove($workspace, 'easywi-backup-work-');
            }
            $snapshot->cleanup();
        }
    }

    public function restore(BackupPlan $plan, string $archivePath, bool $dryRun = false): RestoreReport
    {
        $adapter = $this->adapterRegistry->forModule($plan->module());
        $workspace = null;
        try {
            if (str_contains($archivePath, '://') || !is_file($archivePath) || !is_readable($archivePath)) {
                throw new \InvalidArgumentException('Backup archive is not a readable local file.');
            }
            $codec = new BackupArchiveCodec($this->heartbeat);
            $preparedArchive = $archivePath;
            if (($plan->options()['encrypt'] ?? false) === true) {
                $workspace = BackupTemporaryDirectory::create();
                $preparedArchive = $workspace.'/decrypted.tar';
                $codec->decrypt($archivePath, $preparedArchive, self::encryptionKey($plan));
            }
            if ($codec->isGzip($preparedArchive)) {
                $workspace ??= BackupTemporaryDirectory::create();
                $decompressed = $workspace.'/expanded.tar';
                $codec->gunzip($preparedArchive, $decompressed);
                $preparedArchive = $decompressed;
            }

            return $adapter->restore($plan->resourceId(), $preparedArchive, $dryRun);
        } catch (\Throwable $exception) {
            return new RestoreReport($dryRun, false, 'Backup archive preparation failed: '.$exception->getMessage());
        } finally {
            if ($workspace !== null) {
                BackupTemporaryDirectory::remove($workspace, 'easywi-backup-work-');
            }
        }
    }

    private function archivePath(string $sourcePath, string $archivePath, ?int $maximumMemberBytes): void
    {
        $source = rtrim($sourcePath, '/');
        if (!is_dir($source)) {
            throw new \InvalidArgumentException('Backup source path does not exist: '.$source);
        }
        (new BackupTarWriter($this->heartbeat))->write($source, $archivePath, maximumMemberBytes: $maximumMemberBytes);
    }

    private static function filenameComponent(string $value): string
    {
        return strlen($value) <= 100 && $value !== '.' && $value !== '..'
            && preg_match('/^[A-Za-z0-9_.-]+$/D', $value) === 1
            ? $value : hash('sha256', $value);
    }

    private static function encryptionKey(BackupPlan $plan): string
    {
        $key = self::configText($plan->options()['encryption_key'] ?? '');
        if ($key === '') {
            throw new \InvalidArgumentException('Encryption was enabled but encryption_key is empty.');
        }
        return $key;
    }

    private static function configText(mixed $value): string
    {
        if ($value === null || is_scalar($value)) {
            return (string) $value;
        }
        throw new \InvalidArgumentException('Backup configuration fields must contain scalar values.');
    }
}
