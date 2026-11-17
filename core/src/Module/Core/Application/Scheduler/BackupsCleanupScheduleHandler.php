<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Scheduler;

use App\Module\Core\Application\Backup\BackupRetentionRunner;

final readonly class BackupsCleanupScheduleHandler implements ScheduleHandlerInterface
{
    public function __construct(private BackupRetentionRunner $runner)
    {
    }

    #[\Override]
    public function type(): string { return 'cleanup.backups'; }

    /** @return list<InternalSchedule> */
    #[\Override]
    public function schedules(): array
    {
        return [new InternalSchedule('system', $this->type(), 'Backup Cleanup', $this->type(), 'core', '30 * * * *', true)];
    }

    #[\Override]
    public function runDue(?\DateTimeImmutable $now = null): ScheduleExecutionResult
    {
        try {
            $reports = $this->runner->runAll($now);
        } catch (\Throwable) {
            return ScheduleExecutionResult::failed('Unable to load backup retention plans. Check migrations and encryption keys.');
        }
        $deleted = 0;
        $failed = 0;
        $busy = 0;
        foreach ($reports as $report) {
            $deleted += count($report->deleted);
            if ($report->status === 'failed') {
                ++$failed;
            } elseif ($report->status === 'busy') {
                ++$busy;
            }
        }
        if ($failed > 0) {
            return ScheduleExecutionResult::failed(sprintf('Deleted %d archives; %d plans require attention.', $deleted, $failed));
        }

        return ScheduleExecutionResult::success(sprintf('Deleted %d archives across %d explicitly enabled retention plans; %d busy plans deferred.', $deleted, count($reports), $busy));
    }

    #[\Override]
    public function runNow(string $source, string $id, ?\DateTimeImmutable $now = null): ScheduleExecutionResult
    {
        return $source === 'system' && $id === $this->type() ? $this->runDue($now) : ScheduleExecutionResult::failed('Unknown backup cleanup schedule.');
    }
}
