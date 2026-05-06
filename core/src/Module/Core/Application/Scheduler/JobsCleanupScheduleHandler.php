<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Scheduler;

final class JobsCleanupScheduleHandler extends PlaceholderScheduleHandler
{
    public function type(): string { return 'cleanup.jobs'; }
    protected function label(): string { return 'Job Cleanup'; }
    protected function module(): string { return 'core'; }
    protected function cronExpression(): string { return '15 * * * *'; }
}
