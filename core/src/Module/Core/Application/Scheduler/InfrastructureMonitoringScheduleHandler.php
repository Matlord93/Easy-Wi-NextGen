<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Scheduler;

use App\Module\Nodes\Application\Monitoring\InfrastructureCollector;
use App\Module\Nodes\Application\Monitoring\InfrastructureMonitoringSettings;

final readonly class InfrastructureMonitoringScheduleHandler implements ScheduleHandlerInterface
{
    public function __construct(private InfrastructureCollector $collector, private InfrastructureMonitoringSettings $settings)
    {
    }

    #[\Override]
    public function type(): string
    {
        return 'infrastructure.monitoring';
    }

    #[\Override]
    public function schedules(): array
    {
        $state = $this->settings->state();

        return [new InternalSchedule('system', $this->type(), 'VM/node monitoring', $this->type(), 'infrastructure', '* * * * *', (bool) $this->settings->get()['enabled'], [], isset($state['last_run_at']) ? new \DateTimeImmutable($state['last_run_at']) : null)];
    }

    #[\Override]
    public function runDue(?\DateTimeImmutable $now = null): ScheduleExecutionResult
    {
        return $this->result($this->collector->run(false, $now));
    }

    #[\Override]
    public function runNow(string $source, string $id, ?\DateTimeImmutable $now = null): ScheduleExecutionResult
    {
        if ('system' !== $source || $id !== $this->type()) {
            return ScheduleExecutionResult::failed('Unknown infrastructure schedule.');
        }

        return $this->result($this->collector->run(true, $now));
    }

    private function result(array $result): ScheduleExecutionResult
    {
        return $result['skipped'] ? ScheduleExecutionResult::skipped('Monitoring disabled, already processed, or locked.') : ScheduleExecutionResult::success(sprintf('Saved %d samples; %d unavailable; pruned %d.', $result['samples'], $result['unavailable'], $result['pruned']));
    }
}
