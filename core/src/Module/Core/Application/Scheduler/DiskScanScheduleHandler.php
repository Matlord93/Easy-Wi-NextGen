<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Scheduler;

use App\Module\Core\Application\DiskScanScheduler;

final readonly class DiskScanScheduleHandler implements ScheduleHandlerInterface
{
    public function __construct(private DiskScanScheduler $scans)
    {
    }

    #[\Override]
    public function type(): string
    {
        return 'core.disk_scan';
    }

    #[\Override]
    public function schedules(): array
    {
        return [new InternalSchedule('system', $this->type(), 'Speicherbelegung prüfen', $this->type(), 'core', '* * * * *', true)];
    }

    #[\Override]
    public function runDue(?\DateTimeImmutable $now = null): ScheduleExecutionResult
    {
        $queued = $this->scans->run($now ?? new \DateTimeImmutable());

        return array_sum($queued) > 0
            ? ScheduleExecutionResult::success(sprintf('%d Gameserver- und %d Host-Messungen eingeplant.', $queued['scans'], $queued['stats']))
            : ScheduleExecutionResult::skipped('Keine Speichermessung fällig.');
    }

    #[\Override]
    public function runNow(string $source, string $id, ?\DateTimeImmutable $now = null): ScheduleExecutionResult
    {
        return 'system' === $source && $id === $this->type() ? $this->runDue($now) : ScheduleExecutionResult::failed('Unbekannte Speichermessung.');
    }
}
