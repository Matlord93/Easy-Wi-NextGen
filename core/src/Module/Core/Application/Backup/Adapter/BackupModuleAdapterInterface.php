<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup\Adapter;

interface BackupModuleAdapterInterface
{
    public function module(): string;

    public function snapshot(string $resourceId): BackupSnapshot;

    public function restore(string $resourceId, string $archivePath, bool $dryRun = false): RestoreReport;
}
