<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup;

use App\Module\Core\Application\Backup\Target\BackupTargetWriterInterface;

final class BackupTargetWriterRegistry
{
    /** @param iterable<BackupTargetWriterInterface> $writers */
    public function __construct(private readonly iterable $writers)
    {
    }

    public function write(BackupStorageTarget $target, string $archiveName, string $sourceFile): string
    {
        foreach ($this->writers as $writer) {
            if ($writer->supports($target)) {
                return $writer->write($target, $archiveName, $sourceFile);
            }
        }

        throw new \InvalidArgumentException('No target writer configured for type '.$target->type());
    }
}
