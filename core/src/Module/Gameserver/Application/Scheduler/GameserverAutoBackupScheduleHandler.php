<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application\Scheduler;

use App\Module\Core\Application\Scheduler\InternalSchedule;
use App\Module\Core\Application\Scheduler\ScheduleExecutionResult;
use App\Module\Core\Application\Scheduler\ScheduleHandlerInterface;
use App\Module\Core\Domain\Entity\BackupSchedule;
use App\Module\Core\Domain\Enum\BackupTargetType;
use App\Module\Gameserver\Application\GameserverBackupScheduleRunner;
use App\Repository\BackupScheduleRepository;
use Cron\CronExpression;

final class GameserverAutoBackupScheduleHandler implements ScheduleHandlerInterface
{
    public function __construct(
        private readonly BackupScheduleRepository $repository,
        private readonly GameserverBackupScheduleRunner $runner,
    ) {
    }

    public function type(): string
    {
        return 'gameserver.auto_backup';
    }

    public function schedules(): array
    {
        return array_values(array_filter(array_map(function (BackupSchedule $schedule): ?InternalSchedule {
            $definition = $schedule->getDefinition();
            if ($definition->getTargetType() !== BackupTargetType::Game) {
                return null;
            }

            return new InternalSchedule(
                'backup_schedule',
                (string) ($schedule->getId() ?? ''),
                sprintf('Gameserver Backup #%s', $schedule->getId() ?? '?'),
                $this->type(),
                'gameserver',
                $schedule->getCronExpression(),
                $schedule->isEnabled(),
                ['definition_id' => $definition->getId(), 'instance_id' => $definition->getTargetId()],
                $schedule->getLastRunAt(),
                $schedule->getLastQueuedAt(),
                $this->nextRunAt($schedule->getCronExpression(), $schedule->getTimeZone()),
                $schedule->getLastStatus(),
                $schedule->getLastErrorCode(),
                null,
                null,
                $schedule->getCreatedAt(),
                $schedule->getUpdatedAt(),
            );
        }, $this->repository->findBy([], ['id' => 'ASC']))));
    }

    public function runDue(?\DateTimeImmutable $now = null): ScheduleExecutionResult
    {
        $queued = $this->runner->runDue($now);

        return $queued > 0
            ? ScheduleExecutionResult::success(sprintf('Queued %d gameserver backup job(s).', $queued), $this->runner->getLastCreatedJobIds())
            : ScheduleExecutionResult::skipped('No due gameserver backup schedules.');
    }

    public function runNow(string $source, string $id, ?\DateTimeImmutable $now = null): ScheduleExecutionResult
    {
        if ($source !== 'backup_schedule') {
            return ScheduleExecutionResult::failed('Invalid schedule source for gameserver auto-backup.');
        }

        $schedule = $this->repository->find((int) $id);
        if (!$schedule instanceof BackupSchedule) {
            return ScheduleExecutionResult::failed('Backup schedule not found.');
        }

        $queued = $this->runner->runScheduleNow($schedule, $now);

        return $queued > 0
            ? ScheduleExecutionResult::success('Queued gameserver backup immediately.', $this->runner->getLastCreatedJobIds())
            : ScheduleExecutionResult::skipped('Gameserver backup schedule did not queue a job.');
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
