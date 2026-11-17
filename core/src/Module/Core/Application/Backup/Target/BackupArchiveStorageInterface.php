<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup\Target;

use App\Module\Core\Application\Backup\BackupRun;
use App\Module\Core\Application\Backup\BackupStorageTarget;
use App\Module\Core\Application\Backup\RemoteArchiveProof;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.backup_archive_storage')]
interface BackupArchiveStorageInterface extends BackupTargetWriterInterface
{
    /** @param callable():void $heartbeat */
    public function inspect(BackupStorageTarget $target, string $location, callable $heartbeat): RemoteArchiveProof;

    /** @param callable():void $heartbeat */
    public function delete(BackupStorageTarget $target, BackupRun $run, RemoteArchiveProof $proof, callable $heartbeat): void;
}
