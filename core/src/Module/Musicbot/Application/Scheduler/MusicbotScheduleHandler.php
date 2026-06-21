<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application\Scheduler;

use App\Module\Core\Application\Scheduler\InternalSchedule;
use App\Module\Core\Application\Scheduler\ScheduleExecutionResult;
use App\Module\Core\Application\Scheduler\ScheduleHandlerInterface;
use App\Module\Musicbot\Application\MusicbotScheduleService;
use App\Module\Musicbot\Domain\Entity\MusicbotSchedule;
use App\Repository\MusicbotScheduleRepository;

final class MusicbotScheduleHandler implements ScheduleHandlerInterface
{
    public function __construct(
        private readonly MusicbotScheduleRepository $repository,
        private readonly MusicbotScheduleService $scheduleService,
    ) {
    }

    public function type(): string
    {
        return 'musicbot.schedule';
    }

    /** @return InternalSchedule[] */
    public function schedules(): array
    {
        return array_values(array_map(function (MusicbotSchedule $schedule): InternalSchedule {
            return new InternalSchedule(
                'musicbot_schedule',
                (string) ($schedule->getId() ?? ''),
                sprintf('%s — %s', $schedule->getInstance()->getName(), $schedule->getName()),
                $this->type(),
                'musicbot',
                $schedule->getCronExpression(),
                $schedule->isEnabled(),
                ['action' => $schedule->getAction()->value],
                $schedule->getLastRunAt(),
                null,
                $schedule->getNextRunAt(),
                $schedule->getLastError() !== null ? 'failed' : ($schedule->getLastRunAt() !== null ? 'success' : null),
                $schedule->getLastError(),
                null,
                null,
                $schedule->getCreatedAt(),
                $schedule->getUpdatedAt(),
            );
        }, $this->repository->findBy(['enabled' => true], ['nextRunAt' => 'ASC'])));
    }

    public function runDue(?\DateTimeImmutable $now = null): ScheduleExecutionResult
    {
        $now ??= new \DateTimeImmutable();
        $jobIds = $this->scheduleService->runDue($now);

        return $jobIds !== []
            ? ScheduleExecutionResult::success(sprintf('Queued %d musicbot schedule job(s).', count($jobIds)), $jobIds)
            : ScheduleExecutionResult::skipped('No due musicbot schedules.');
    }

    public function runNow(string $source, string $id, ?\DateTimeImmutable $now = null): ScheduleExecutionResult
    {
        if ($source !== 'musicbot_schedule') {
            return ScheduleExecutionResult::failed('Invalid schedule source for musicbot schedule handler.');
        }

        $schedule = $this->repository->find((int) $id);
        if (!$schedule instanceof MusicbotSchedule) {
            return ScheduleExecutionResult::failed(sprintf('Musicbot schedule #%s not found.', $id));
        }

        try {
            $job = $this->scheduleService->dispatchScheduleJobPublic($schedule);

            return ScheduleExecutionResult::success(
                sprintf('Musicbot schedule "%s" dispatched immediately.', $schedule->getName()),
                [$job->getId()],
            );
        } catch (\Throwable $e) {
            return ScheduleExecutionResult::failed($e->getMessage());
        }
    }
}
