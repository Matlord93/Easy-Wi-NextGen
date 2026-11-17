<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup;

use App\Module\Core\Application\Backup\Target\BackupArchiveStorageInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class BackupArchiveStorageRegistry
{
    /** @param iterable<BackupArchiveStorageInterface> $storages */
    public function __construct(#[AutowireIterator('app.backup_archive_storage')] private iterable $storages)
    {
    }

    public function forTarget(BackupStorageTarget $target): BackupArchiveStorageInterface
    {
        foreach ($this->storages as $storage) {
            if ($storage->supports($target)) {
                return $storage;
            }
        }

        throw new \RuntimeException('Archive inspection/deletion is unavailable for target '.$target->type().'.');
    }
}
