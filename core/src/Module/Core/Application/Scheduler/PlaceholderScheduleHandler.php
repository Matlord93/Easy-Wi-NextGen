<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Scheduler;

abstract class PlaceholderScheduleHandler implements ScheduleHandlerInterface
{
    #[\Override]
    public function schedules(): array
    {
        return [new InternalSchedule(
            'system',
            $this->type(),
            $this->label(),
            $this->type(),
            $this->module(),
            $this->cronExpression(),
            false,
            ['placeholder' => true],
            null,
            null,
            null,
            'skipped',
            'Handler is registered as a central scheduler extension point and not enabled yet.',
        )];
    }

    #[\Override]
    public function runDue(?\DateTimeImmutable $now = null): ScheduleExecutionResult
    {
        return ScheduleExecutionResult::skipped($this->label().' is not enabled.');
    }

    #[\Override]
    public function runNow(string $source, string $id, ?\DateTimeImmutable $now = null): ScheduleExecutionResult
    {
        return ScheduleExecutionResult::skipped($this->label().' is not enabled.');
    }

    abstract protected function label(): string;
    abstract protected function module(): string;
    protected function cronExpression(): string { return '*/5 * * * *'; }
}
