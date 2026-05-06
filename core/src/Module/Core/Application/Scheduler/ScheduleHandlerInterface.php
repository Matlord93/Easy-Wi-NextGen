<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Scheduler;

interface ScheduleHandlerInterface
{
    public function type(): string;

    /** @return InternalSchedule[] */
    public function schedules(): array;

    public function runDue(?\DateTimeImmutable $now = null): ScheduleExecutionResult;

    public function runNow(string $source, string $id, ?\DateTimeImmutable $now = null): ScheduleExecutionResult;
}
