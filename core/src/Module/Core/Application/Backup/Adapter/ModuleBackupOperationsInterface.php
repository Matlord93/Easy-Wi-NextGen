<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup\Adapter;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** Module-specific artifact handling; implementations must report actual outcomes. */
#[AutoconfigureTag('app.module_backup_operations')]
interface ModuleBackupOperationsInterface
{
    public function module(): string;

    public function snapshot(string $resourceId): BackupSnapshot;

    public function restore(string $resourceId, string $archivePath, bool $dryRun): RestoreReport;
}
