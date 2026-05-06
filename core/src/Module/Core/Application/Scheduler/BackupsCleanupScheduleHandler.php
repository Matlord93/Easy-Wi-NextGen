<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Scheduler;

final class BackupsCleanupScheduleHandler extends PlaceholderScheduleHandler
{
    public function type(): string { return 'cleanup.backups'; }
    protected function label(): string { return 'Backup Cleanup'; }
    protected function module(): string { return 'core'; }
    protected function cronExpression(): string { return '30 * * * *'; }
}
