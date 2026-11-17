<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup\Adapter;

use App\Module\Core\Application\Backup\BackupTemporaryDirectory;
use App\Module\Core\Application\Backup\ModuleArchiveGatewayInterface;
use App\Module\Core\Application\Backup\Resource\MailBackupMetadata;
use App\Module\Core\Application\Backup\Resource\ModuleBackupBundle;
use App\Module\Core\Application\Backup\Resource\ModuleBackupResourceResolver;

/** Real completed resource operations, shared by the four independently registered modules. */
abstract class ManagedModuleBackupOperations implements ModuleBackupOperationsInterface
{
    public function __construct(
        private readonly ModuleBackupResourceResolver $resources,
        private readonly ModuleArchiveGatewayInterface $gateway,
        private readonly ModuleBackupBundle $bundles,
        private readonly MailBackupMetadata $mailMetadata,
    ) {
    }

    #[\Override]
    public function snapshot(string $resourceId): BackupSnapshot
    {
        $resource = $this->resources->resolve($this->module(), $resourceId);
        $directory = BackupTemporaryDirectory::create('easywi-backup-snapshot-');
        try {
            $metadata = $this->module() === 'mail' ? $this->mailMetadata->snapshot($resource) : [];
            $this->gateway->snapshot($resource->agent, $resource->customerId, $resource->descriptor, $directory.'/payload.tar.gz');
            $this->bundles->seal($resource, $directory, $metadata);
            return BackupSnapshot::temporaryDirectory($directory, $this->module().'-'.str_replace(':', '-', $resourceId).'.tar');
        } catch (\Throwable $error) {
            BackupTemporaryDirectory::remove($directory, 'easywi-backup-snapshot-');
            throw $error;
        }
    }

    #[\Override]
    public function restore(string $resourceId, string $archivePath, bool $dryRun): RestoreReport
    {
        $directory = BackupTemporaryDirectory::create('easywi-backup-snapshot-');
        try {
            $resource = $this->resources->resolve($this->module(), $resourceId);
            $metadata = $this->bundles->open($resource, $archivePath, $directory);
            if ($this->module() !== 'mail') {
                return $this->gateway->restore($resource->agent, $resource->customerId, $resource->descriptor, $directory.'/payload.tar.gz', $dryRun);
            }
            $this->mailMetadata->validate($resource, $metadata);
            if ($dryRun) {
                return $this->gateway->restore($resource->agent, $resource->customerId, $resource->descriptor, $directory.'/payload.tar.gz', true);
            }
            $connection = $this->mailMetadata->connection();
            $connection->beginTransaction();
            try {
                $this->mailMetadata->restore($resource, $metadata);
                $report = $this->gateway->restore($resource->agent, $resource->customerId, $resource->descriptor, $directory.'/payload.tar.gz', false);
                if (!$report->success()) {
                    $connection->rollBack();
                    return $report;
                }
                $connection->commit();
                return new RestoreReport(false, true, 'Mail messages, routing configuration, DKIM keys and control-plane settings restored.');
            } catch (\Throwable $error) {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }
                throw $error;
            }
        } catch (\Throwable) {
            // Connection/SQL/Agent exceptions may contain credentials; never expose them to customers.
            return new RestoreReport($dryRun, false, 'Module restore failed. Verify resource binding, archive identity, metadata, Agent service configuration and server logs; no completed restore was confirmed.');
        } finally {
            BackupTemporaryDirectory::remove($directory, 'easywi-backup-snapshot-');
        }
    }
}
