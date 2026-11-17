<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup\Adapter;

abstract class DelegatingBackupAdapter implements BackupModuleAdapterInterface
{
    public function __construct(private readonly ?ModuleBackupOperationsRegistry $operations = null)
    {
    }

    #[\Override]
    public function snapshot(string $resourceId): BackupSnapshot
    {
        $operation = $this->operations?->find($this->module());
        if ($operation === null) {
            throw new \LogicException('No backup operation provider configured for '.$this->module().'.');
        }

        return $operation->snapshot($resourceId);
    }

    #[\Override]
    public function restore(string $resourceId, string $archivePath, bool $dryRun = false): RestoreReport
    {
        if (str_contains($archivePath, "\0") || str_contains($archivePath, '://') || !is_file($archivePath)) {
            return new RestoreReport($dryRun, false, 'Backup archive is not a readable local file.');
        }
        $operation = $this->operations?->find($this->module());
        if ($operation === null) {
            return new RestoreReport($dryRun, false, 'No restore operation provider configured for '.$this->module().'. No restore was performed.');
        }

        return $operation->restore($resourceId, $archivePath, $dryRun);
    }
}
