<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Scheduler;

final class JobsCleanupScheduleHandler extends PlaceholderScheduleHandler
{
    #[\Override]
    public function type(): string { return 'cleanup.jobs'; }
    #[\Override]
    protected function label(): string { return 'Job Cleanup'; }
    #[\Override]
    protected function module(): string { return 'core'; }
    #[\Override]
    protected function cronExpression(): string { return '15 * * * *'; }
}
