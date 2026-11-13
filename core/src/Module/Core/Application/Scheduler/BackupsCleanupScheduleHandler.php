<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Scheduler;

final class BackupsCleanupScheduleHandler extends PlaceholderScheduleHandler
{
    #[\Override]
    public function type(): string { return 'cleanup.backups'; }
    #[\Override]
    protected function label(): string { return 'Backup Cleanup'; }
    #[\Override]
    protected function module(): string { return 'core'; }
    #[\Override]
    protected function cronExpression(): string { return '30 * * * *'; }
}
