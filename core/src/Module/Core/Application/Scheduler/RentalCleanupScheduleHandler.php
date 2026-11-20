<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Scheduler;

use App\Module\Core\Application\Contracts\RentalCleanup;

final readonly class RentalCleanupScheduleHandler implements ScheduleHandlerInterface
{
    public function __construct(private RentalCleanup $cleanup)
    {
    }

    #[\Override]
    public function type(): string
    {
        return 'rentals.cleanup';
    }

    #[\Override]
    public function schedules(): array
    {
        return [new InternalSchedule('system', 'rentals.cleanup', 'Aufbewahrung beendeter Mietressourcen', $this->type(), 'billing', '*/15 * * * *', true)];
    }

    #[\Override]
    public function runDue(?\DateTimeImmutable $now = null): ScheduleExecutionResult
    {
        return $this->runNow('system', 'rentals.cleanup', $now);
    }

    #[\Override]
    public function runNow(string $source, string $id, ?\DateTimeImmutable $now = null): ScheduleExecutionResult
    {
        if ('system' !== $source || 'rentals.cleanup' !== $id) {
            return ScheduleExecutionResult::failed('Unknown cleanup schedule.');
        }
        $this->cleanup->run($now ?? new \DateTimeImmutable());

        return ScheduleExecutionResult::success('Aufbewahrungsfristen geprüft.');
    }
}
