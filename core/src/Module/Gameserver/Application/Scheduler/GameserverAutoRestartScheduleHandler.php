<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application\Scheduler;

use App\Module\Core\Application\Scheduler\InternalSchedule;
use App\Module\Core\Application\Scheduler\ScheduleExecutionResult;
use App\Module\Core\Application\Scheduler\ScheduleHandlerInterface;
use App\Module\Core\Domain\Entity\InstanceSchedule;
use App\Module\Core\Domain\Enum\InstanceScheduleAction;
use App\Module\Gameserver\Application\GameserverInstanceScheduleRunner;
use App\Repository\InstanceScheduleRepository;
use Cron\CronExpression;

final class GameserverAutoRestartScheduleHandler implements ScheduleHandlerInterface
{
    public function __construct(
        private readonly InstanceScheduleRepository $repository,
        private readonly GameserverInstanceScheduleRunner $runner,
    ) {
    }

    public function type(): string
    {
        return 'gameserver.auto_restart';
    }

    public function schedules(): array
    {
        return array_values(array_map(function (InstanceSchedule $schedule): InternalSchedule {
            $instance = $schedule->getInstance();

            return new InternalSchedule(
                'instance_schedule',
                (string) ($schedule->getId() ?? ''),
                sprintf('Gameserver Restart #%s', $schedule->getId() ?? '?'),
                $this->type(),
                'gameserver',
                $schedule->getCronExpression(),
                $schedule->isEnabled(),
                ['instance_id' => $instance->getId(), 'action' => $schedule->getAction()->value],
                $schedule->getLastRunAt(),
                $schedule->getLastQueuedAt(),
                $this->nextRunAt($schedule->getCronExpression(), $schedule->getTimeZone() ?? 'UTC'),
                $schedule->getLastStatus(),
                $schedule->getLastErrorCode(),
                null,
                null,
                $schedule->getCreatedAt(),
                $schedule->getUpdatedAt(),
            );
        }, $this->repository->findBy(['action' => InstanceScheduleAction::Restart], ['id' => 'ASC'])));
    }

    public function runDue(?\DateTimeImmutable $now = null): ScheduleExecutionResult
    {
        $queued = $this->runner->runDue($now);

        return $queued > 0
            ? ScheduleExecutionResult::success(sprintf('Queued %d gameserver restart job(s).', $queued), $this->runner->getLastCreatedJobIds())
            : ScheduleExecutionResult::skipped('No due gameserver restart schedules.');
    }

    public function runNow(string $source, string $id, ?\DateTimeImmutable $now = null): ScheduleExecutionResult
    {
        if ($source !== 'instance_schedule') {
            return ScheduleExecutionResult::failed('Invalid schedule source for gameserver auto-restart.');
        }

        $schedule = $this->repository->find((int) $id);
        if (!$schedule instanceof InstanceSchedule) {
            return ScheduleExecutionResult::failed('Instance schedule not found.');
        }

        $queued = $this->runner->runScheduleNow($schedule, $now);

        return $queued > 0
            ? ScheduleExecutionResult::success('Queued gameserver restart immediately.', $this->runner->getLastCreatedJobIds())
            : ScheduleExecutionResult::skipped('Gameserver restart schedule did not queue a job.');
    }

    private function nextRunAt(string $cronExpression, string $timeZone): ?\DateTimeImmutable
    {
        if (!CronExpression::isValidExpression($cronExpression)) {
            return null;
        }

        try {
            $cron = CronExpression::factory($cronExpression);
            $next = $cron->getNextRunDate(new \DateTimeImmutable('now', new \DateTimeZone($timeZone ?: 'UTC')), 0, true);
            return \DateTimeImmutable::createFromMutable($next)->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }
}
