<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup\Adapter;

final class GameModuleBackupOperations extends ManagedModuleBackupOperations
{
    #[\Override]
    public function module(): string
    {
        return 'game';
    }
}
