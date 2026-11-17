<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup\Adapter;

final class GameBackupAdapter extends DelegatingBackupAdapter
{
    #[\Override]
    public function module(): string
    {
        return 'game';
    }
}
