<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Scheduler;

use App\Module\Core\Domain\Entity\BackupSchedule;
use App\Module\Core\Domain\Entity\InstanceSchedule;
use App\Module\Core\Domain\Enum\BackupTargetType;
use App\Module\Core\Domain\Enum\InstanceScheduleAction;
use App\Repository\BackupScheduleRepository;
use App\Repository\InstanceScheduleRepository;
use Cron\CronExpression;

final class InternalScheduleProvider
{
    public function __construct(
        private readonly ScheduleHandlerRegistry $registry,
        private readonly BackupScheduleRepository $backupScheduleRepository,
        private readonly InstanceScheduleRepository $instanceScheduleRepository,
    ) {
    }

    /** @return InternalSchedule[] */
    public function all(): array
    {
        $schedules = [];
        foreach ($this->registry->all() as $handler) {
            array_push($schedules, ...$handler->schedules());
        }
        array_push($schedules, ...$this->unassignedBackupSchedules(), ...$this->legacyInstanceSchedules());

        usort($schedules, static fn (InternalSchedule $a, InternalSchedule $b): int => [$a->module, $a->name] <=> [$b->module, $b->name]);

        return $schedules;
    }

    /** @return InternalSchedule[] */
    private function unassignedBackupSchedules(): array
    {
        return array_values(array_filter(array_map(function (BackupSchedule $schedule): ?InternalSchedule {
            $definition = $schedule->getDefinition();
            if ($definition->getTargetType() === BackupTargetType::Game) {
                return null;
            }

            return new InternalSchedule(
                'backup_schedule',
                (string) ($schedule->getId() ?? ''),
                sprintf('Backup #%s (%s)', $schedule->getId() ?? '?', $definition->getTargetType()->value),
                'unassigned.backup_schedule',
                $definition->getTargetType()->value,
                $schedule->getCronExpression(),
                $schedule->isEnabled(),
                [
                    'definition_id' => $definition->getId(),
                    'target_type' => $definition->getTargetType()->value,
                    'warning' => 'Dieser Zeitplan ist gespeichert, aber aktuell keinem aktiven Scheduler-Handler zugeordnet.',
                ],
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
        }, $this->backupScheduleRepository->findBy([], ['id' => 'ASC']))));
    }

    /** @return InternalSchedule[] */
    private function legacyInstanceSchedules(): array
    {
        return array_values(array_filter(array_map(function (InstanceSchedule $schedule): ?InternalSchedule {
            if ($schedule->getAction() === InstanceScheduleAction::Restart) {
                return null;
            }

            $instance = $schedule->getInstance();

            return new InternalSchedule(
                'instance_schedule',
                (string) ($schedule->getId() ?? ''),
                sprintf('Gameserver %s #%s', ucfirst($schedule->getAction()->value), $schedule->getId() ?? '?'),
                'legacy.instance_schedule',
                'gameserver',
                $schedule->getCronExpression(),
                $schedule->isEnabled(),
                ['instance_id' => $instance->getId(), 'action' => $schedule->getAction()->value, 'legacy_app_run_schedules' => true],
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
        }, $this->instanceScheduleRepository->findBy([], ['id' => 'ASC']))));
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
