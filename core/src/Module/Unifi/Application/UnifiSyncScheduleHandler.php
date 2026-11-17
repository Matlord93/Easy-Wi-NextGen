<?php

declare(strict_types=1);

namespace App\Module\Unifi\Application;

use App\Module\Core\Application\Scheduler\InternalSchedule;
use App\Module\Core\Application\Scheduler\ScheduleExecutionResult;
use App\Module\Core\Application\Scheduler\ScheduleHandlerInterface;

final readonly class UnifiSyncScheduleHandler implements ScheduleHandlerInterface
{
    public function __construct(private UnifiSyncOutboxWorker $worker)
    {
    }

    #[\Override]
    public function type(): string
    {
        return 'unifi.ports.sync';
    }

    /** @return list<InternalSchedule> */
    #[\Override]
    public function schedules(): array
    {
        return [new InternalSchedule('system', $this->type(), 'Pending UniFi port synchronizations', $this->type(), 'unifi', '* * * * *', true)];
    }

    #[\Override]
    public function runDue(?\DateTimeImmutable $now = null): ScheduleExecutionResult
    {
        $result = $this->worker->drain();
        if ($result['failed'] > 0) {
            return ScheduleExecutionResult::failed(sprintf('%d UniFi synchronizations failed; durable retry remains pending.', $result['failed']));
        }
        if ($result['attempted'] === 0) {
            return ScheduleExecutionResult::skipped('No committed UniFi changes are due.');
        }
        if ($result['succeeded'] === 0 && $result['skipped'] > 0) {
            return ScheduleExecutionResult::skipped('UniFi integration is disabled; committed changes remain pending.');
        }
        return ScheduleExecutionResult::success(sprintf('Synchronized %d UniFi requests; deferred %d while disabled.', $result['succeeded'], $result['skipped']));
    }

    #[\Override]
    public function runNow(string $source, string $id, ?\DateTimeImmutable $now = null): ScheduleExecutionResult
    {
        return $source === 'system' && $id === $this->type() ? $this->runDue($now) : ScheduleExecutionResult::failed('Unknown UniFi schedule.');
    }
}
