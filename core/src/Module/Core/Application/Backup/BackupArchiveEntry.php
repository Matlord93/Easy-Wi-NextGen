<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup;

final readonly class BackupArchiveEntry
{
    public function __construct(public BackupRun $run, public string $targetType, public ?string $localRoot, public ?string $targetIdentity = null)
    {
    }
}
