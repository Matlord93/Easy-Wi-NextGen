<?php

declare(strict_types=1);

namespace App\Module\Core\Command;

use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\DiskEnforcementService;
use App\Module\Core\Application\JobLogger;
use App\Module\Core\Domain\Entity\Backup;
use App\Module\Core\Domain\Entity\BackupDefinition;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Enum\BackupStatus;
use App\Module\Core\Domain\Enum\BackupTargetType;
use App\Module\Core\Domain\Enum\InstanceScheduleAction;
use App\Module\Core\Domain\Enum\InstanceStatus;
use App\Module\Core\Domain\Enum\InstanceUpdatePolicy;
use App\Module\Gameserver\Application\InstanceJobPayloadBuilder;
use App\Repository\BackupScheduleRepository;
use App\Repository\BackupTargetRepository;
use App\Repository\InstanceRepository;
use App\Repository\InstanceScheduleRepository;
use App\Repository\JobRepository;
use Cron\CronExpression;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:run-schedules',
    description: 'Queue instance and backup schedules.',
)]
final class RunSchedulesCommand extends Command
{
    private const DEFAULT_BATCH_SIZE = 250;

    public function __construct(
        private readonly InstanceScheduleRepository $instanceScheduleRepository,
        private readonly BackupScheduleRepository $backupScheduleRepository,
        private readonly InstanceRepository $instanceRepository,
        private readonly InstanceJobPayloadBuilder $instanceJobPayloadBuilder,
        private readonly DiskEnforcementService $diskEnforcementService,
        private readonly JobLogger $jobLogger,
        private readonly AuditLogger $auditLogger,
        private readonly EntityManagerInterface $entityManager,
        private readonly JobRepository $jobRepository,
        private readonly BackupTargetRepository $backupTargetRepository,
        private readonly AppSettingsService $appSettingsService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $lockHandle = $this->acquireProcessLock();
        if ($lockHandle === null) {
            $output->writeln('Scheduler lock is already held. Skipping run.');
            return Command::SUCCESS;
        }

        $now = new \DateTimeImmutable();
        $queued = 0;

        try {
            $lastInstanceScheduleId = 0;
            do {
                $instanceSchedules = $this->instanceScheduleRepository->findEnabledBatchAfterId($lastInstanceScheduleId, self::DEFAULT_BATCH_SIZE);
                foreach ($instanceSchedules as $schedule) {
                    $lastInstanceScheduleId = max($lastInstanceScheduleId, (int) ($schedule->getId() ?? 0));
                    $instance = $schedule->getInstance();
                    $action = $schedule->getAction();

                if ($action === InstanceScheduleAction::Update && $instance->getUpdatePolicy() !== InstanceUpdatePolicy::Auto) {
                    continue;
                }

                $cronExpression = $schedule->getCronExpression();
                if (!CronExpression::isValidExpression($cronExpression)) {
                    continue;
                }

                $timeZone = $schedule->getTimeZone() ?? 'UTC';
                try {
                    $timeZoneObj = new \DateTimeZone($timeZone);
                } catch (\Exception) {
                    continue;
                }

                $nowLocal = $now->setTimezone($timeZoneObj);
                $cron = CronExpression::factory($cronExpression);
                $previousRun = $cron->getPreviousRunDate($nowLocal, 0, true);

                $lastQueuedAt = $schedule->getLastQueuedAt();
                if ($lastQueuedAt !== null && $lastQueuedAt->setTimezone($timeZoneObj) >= $previousRun) {
                    continue;
                }

                if (strtolower($instance->getNode()->getStatus()) !== 'online') {
                    $schedule->markRun($now, 'blocked', 'agent_offline_or_unknown');
                    $schedule->setLastQueuedAt($now);
                    $this->entityManager->persist($schedule);
                    $this->auditLogger->log(null, 'instance.schedule.skipped', [
                        'instance_id' => $instance->getId(),
                        'customer_id' => $instance->getCustomer()->getId(),
                        'action' => $action->value,
                        'reason' => 'agent_offline_or_unknown',
                    ]);
                    continue;
                }

                if ($this->diskEnforcementService->guardInstanceAction($instance, $now) !== null) {
                    $schedule->markRun($now, 'blocked', 'disk_quota_exceeded');
                    $schedule->setLastQueuedAt($now);
                    $this->entityManager->persist($schedule);
                    $this->auditLogger->log(null, 'instance.schedule.skipped', [
                        'instance_id' => $instance->getId(),
                        'customer_id' => $instance->getCustomer()->getId(),
                        'action' => $action->value,
                        'reason' => 'disk_quota_exceeded',
                    ]);
                    continue;
                }

                $job = null;
                $activeJob = $this->findActiveAutomationJob($instance, $action);
                if ($activeJob instanceof Job) {
                    $errorCode = match ($action) {
                        InstanceScheduleAction::Start => 'start_action_in_progress',
                        InstanceScheduleAction::Stop => 'stop_action_in_progress',
                        InstanceScheduleAction::Restart => 'restart_action_in_progress',
                        InstanceScheduleAction::Update => 'update_action_in_progress',
                    };
                    $schedule->markRun($now, 'skipped', $errorCode);
                    $schedule->setLastQueuedAt($now);
                    $this->entityManager->persist($schedule);
                    $this->auditLogger->log(null, 'instance.schedule.skipped', [
                        'instance_id' => $instance->getId(),
                        'customer_id' => $instance->getCustomer()->getId(),
                        'action' => $action->value,
                        'reason' => $errorCode,
                        'blocking_job_id' => $activeJob->getId(),
                        'blocking_job_type' => $activeJob->getType(),
                    ]);
                    continue;
                }

                $lifecycleConflict = $this->findLifecycleConflictJob($instance, $action);
                if ($lifecycleConflict instanceof Job) {
                    $schedule->markRun($now, 'skipped', 'lifecycle_action_in_progress');
                    $schedule->setLastQueuedAt($now);
                    $this->entityManager->persist($schedule);
                    $this->auditLogger->log(null, 'instance.schedule.skipped', [
                        'instance_id' => $instance->getId(),
                        'customer_id' => $instance->getCustomer()->getId(),
                        'action' => $action->value,
                        'reason' => 'lifecycle_action_in_progress',
                        'blocking_job_id' => $lifecycleConflict->getId(),
                        'blocking_job_type' => $lifecycleConflict->getType(),
                    ]);
                    continue;
                }

                if ($action === InstanceScheduleAction::Update && ($instance->getLockedBuildId() !== null || $instance->getLockedVersion() !== null)) {
                    $schedule->markRun($now, 'skipped', 'update_locked_by_pin');
                    $schedule->setLastQueuedAt($now);
                    $this->entityManager->persist($schedule);
                    $this->auditLogger->log(null, 'instance.schedule.skipped', [
                        'instance_id' => $instance->getId(),
                        'customer_id' => $instance->getCustomer()->getId(),
                        'action' => $action->value,
                        'reason' => 'update_locked_by_pin',
                    ]);
                    continue;
                }

                if ($action === InstanceScheduleAction::Start && $instance->getStatus() === InstanceStatus::Running) {
                    $schedule->markRun($now, 'skipped', 'start_already_running');
                    $schedule->setLastQueuedAt($now);
                    $this->entityManager->persist($schedule);
                    $this->auditLogger->log(null, 'instance.schedule.skipped', [
                        'instance_id' => $instance->getId(),
                        'customer_id' => $instance->getCustomer()->getId(),
                        'action' => $action->value,
                        'reason' => 'start_already_running',
                    ]);
                    continue;
                }
                if ($action === InstanceScheduleAction::Stop && $instance->getStatus() === InstanceStatus::Stopped) {
                    $schedule->markRun($now, 'skipped', 'stop_already_stopped');
                    $schedule->setLastQueuedAt($now);
                    $this->entityManager->persist($schedule);
                    $this->auditLogger->log(null, 'instance.schedule.skipped', [
                        'instance_id' => $instance->getId(),
                        'customer_id' => $instance->getCustomer()->getId(),
                        'action' => $action->value,
                        'reason' => 'stop_already_stopped',
                    ]);
                    continue;
                }
                if ($action === InstanceScheduleAction::Restart && $instance->getStatus() === InstanceStatus::Stopped) {
                    $schedule->markRun($now, 'skipped', 'restart_requires_running');
                    $schedule->setLastQueuedAt($now);
                    $this->entityManager->persist($schedule);
                    $this->auditLogger->log(null, 'instance.schedule.skipped', [
                        'instance_id' => $instance->getId(),
                        'customer_id' => $instance->getCustomer()->getId(),
                        'action' => $action->value,
                        'reason' => 'restart_requires_running',
                    ]);
                    continue;
                }

                $job = match ($action) {
                    InstanceScheduleAction::Update => $this->queueUpdateJob($instance, $now),
                    InstanceScheduleAction::Start => $this->queueInstanceJob('instance.start', $instance),
                    InstanceScheduleAction::Stop => $this->queueInstanceJob('instance.stop', $instance),
                    InstanceScheduleAction::Restart => $this->queueInstanceJob('instance.restart', $instance),
                };

                $schedule->setLastQueuedAt($now);
                $schedule->markRun($now, 'queued');
                $this->entityManager->persist($schedule);
                $this->jobLogger->log($job, sprintf('Scheduled %s job queued.', $action->value), 0);
                $this->auditLogger->log(null, 'instance.schedule.queued', [
                    'instance_id' => $instance->getId(),
                    'customer_id' => $instance->getCustomer()->getId(),
                    'action' => $action->value,
                    'cron_expression' => $cronExpression,
                    'time_zone' => $timeZone,
                    'job_id' => $job->getId(),
                ]);
                    $queued++;
                }
            } while (count($instanceSchedules) === self::DEFAULT_BATCH_SIZE);

            $lastBackupScheduleId = 0;
            do {
                $backupSchedules = $this->backupScheduleRepository->findEnabledBatchAfterId($lastBackupScheduleId, self::DEFAULT_BATCH_SIZE);
                foreach ($backupSchedules as $schedule) {
                    $lastBackupScheduleId = max($lastBackupScheduleId, (int) ($schedule->getId() ?? 0));
                    $definition = $schedule->getDefinition();
                    if ($definition->getTargetType() !== BackupTargetType::Game) {
                        continue;
                    }

                $instanceId = (int) $definition->getTargetId();
                $instance = $this->instanceRepository->find($instanceId);
                if ($instance === null) {
                    $schedule->markRun($now, 'skipped', 'instance_not_found');
                    $this->entityManager->persist($schedule);
                    continue;
                }

                $cronExpression = $schedule->getCronExpression();
                if (!CronExpression::isValidExpression($cronExpression)) {
                    $schedule->markRun($now, 'skipped', 'backup_schedule_invalid');
                    $this->entityManager->persist($schedule);
                    continue;
                }

                $scheduleTz = $schedule->getTimeZone() !== '' ? $schedule->getTimeZone() : 'UTC';
                try {
                    $timeZone = new \DateTimeZone($scheduleTz);
                } catch (\Throwable) {
                    $schedule->markRun($now, 'skipped', 'backup_schedule_invalid_timezone');
                    $this->entityManager->persist($schedule);
                    continue;
                }

                $cron = CronExpression::factory($cronExpression);
                $previousRun = $cron->getPreviousRunDate($now->setTimezone($timeZone), 0, true);
                $lastQueuedAt = $schedule->getLastQueuedAt();
                if ($lastQueuedAt !== null && $lastQueuedAt->setTimezone($timeZone) >= $previousRun) {
                    continue;
                }

                if ($this->jobRepository->findLatestActiveByTypesAndInstanceId([
                    'instance.backup.create',
                    'instance.backup.restore',
                    'instance.reinstall',
                    'instance.start',
                    'instance.stop',
                    'instance.restart',
                    'sniper.update',
                    'instance.config.apply',
                    'instance.settings.update',
                    'instance.addon.install',
                    'instance.addon.update',
                    'instance.addon.remove',
                ], $instance->getId() ?? 0) instanceof Job) {
                    $schedule->markRun($now, 'skipped', 'backup_action_in_progress');
                    $schedule->setLastQueuedAt($now);
                    $this->entityManager->persist($schedule);
                    $this->auditLogger->log(null, 'instance.backup.schedule_skipped', [
                        'instance_id' => $instance->getId(),
                        'customer_id' => $instance->getCustomer()->getId(),
                        'definition_id' => $definition->getId(),
                        'reason' => 'backup_action_in_progress',
                    ]);
                    continue;
                }

                if ($this->diskEnforcementService->guardInstanceAction($instance, $now) !== null) {
                    $schedule->markRun($now, 'blocked', 'disk_quota_exceeded');
                    $schedule->setLastQueuedAt($now);
                    $this->entityManager->persist($schedule);
                    $this->auditLogger->log(null, 'instance.backup.schedule_skipped', [
                        'instance_id' => $instance->getId(),
                        'customer_id' => $instance->getCustomer()->getId(),
                        'definition_id' => $definition->getId(),
                        'reason' => 'disk_quota_exceeded',
                    ]);
                    continue;
                }

                $backup = new Backup($definition, BackupStatus::Queued);
                $this->entityManager->persist($backup);
                $this->entityManager->flush();

                $targetId = $this->resolveBackupTargetId($definition, $schedule);
                $job = $this->queueInstanceJob('instance.backup.create', $instance, [
                    'definition_id' => $definition->getId(),
                    'backup_id' => $backup->getId(),
                    'retention_days' => (string) $schedule->getRetentionDays(),
                    'retention_count' => (string) $schedule->getRetentionCount(),
                    'compression' => $schedule->getCompression(),
                    'stop_before' => $schedule->isStopBefore() ? 'true' : 'false',
                    'backup_target_id' => $targetId,
                ]);
                $backup->setJob($job);
                $this->entityManager->persist($backup);
                $schedule->setLastQueuedAt($now);
                $schedule->markRun($now, 'queued');
                $this->entityManager->persist($schedule);
                $this->jobLogger->log($job, 'Scheduled backup queued.', 0);

                $this->auditLogger->log(null, 'instance.backup.schedule_queued', [
                    'instance_id' => $instance->getId(),
                    'customer_id' => $instance->getCustomer()->getId(),
                    'definition_id' => $definition->getId(),
                    'backup_id' => $backup->getId(),
                    'cron_expression' => $cronExpression,
                    'time_zone' => $scheduleTz,
                    'job_id' => $job->getId(),
                    'backup_target_id' => $targetId,
                ]);
                    $queued++;
                }
            } while (count($backupSchedules) === self::DEFAULT_BATCH_SIZE);

            $this->entityManager->flush();

            $output->writeln(sprintf('Queued %d scheduled job(s).', $queued));

            return Command::SUCCESS;
        } finally {
            $this->releaseProcessLock($lockHandle);
        }
    }

    /**
     * @return resource|null
     */
    private function acquireProcessLock()
    {
        $path = sprintf('%s/easywi-run-schedules.lock', sys_get_temp_dir());
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            return null;
        }

        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }

        @ftruncate($handle, 0);
        @fwrite($handle, (string) getmypid());

        return $handle;
    }

    /**
     * @param resource|null $lockHandle
     */
    private function releaseProcessLock($lockHandle): void
    {
        if ($lockHandle === null) {
            return;
        }

        @flock($lockHandle, LOCK_UN);
        @fclose($lockHandle);
    }

    private function queueInstanceJob(string $type, Instance $instance, array $extraPayload = []): Job
    {
        $payload = array_merge([
            'instance_id' => (string) ($instance->getId() ?? ''),
            'customer_id' => (string) $instance->getCustomer()->getId(),
            'node_id' => $instance->getNode()->getId(),
            'agent_id' => $instance->getNode()->getId(),
        ], $extraPayload);

        $job = new Job($type, $payload);
        $this->entityManager->persist($job);

        return $job;
    }

    private function queueUpdateJob(Instance $instance, \DateTimeImmutable $queuedAt): Job
    {
        $job = new Job('sniper.update', $this->instanceJobPayloadBuilder->buildSniperUpdatePayload(
            $instance,
            $instance->getLockedBuildId(),
            $instance->getLockedVersion(),
        ));
        $this->entityManager->persist($job);
        $instance->setLastUpdateQueuedAt($queuedAt);
        $instance->setStatus(InstanceStatus::Provisioning);
        $this->entityManager->persist($instance);

        return $job;
    }

    private function findActiveAutomationJob(Instance $instance, InstanceScheduleAction $action): ?Job
    {
        $instanceId = $instance->getId() ?? 0;
        if ($instanceId <= 0) {
            return null;
        }

        $types = match ($action) {
            InstanceScheduleAction::Start => ['instance.start'],
            InstanceScheduleAction::Stop => ['instance.stop'],
            InstanceScheduleAction::Restart => ['instance.restart'],
            InstanceScheduleAction::Update => ['sniper.update'],
        };

        return $this->jobRepository->findLatestActiveByTypesAndInstanceId($types, $instanceId);
    }

    private function findLifecycleConflictJob(Instance $instance, InstanceScheduleAction $action): ?Job
    {
        $instanceId = $instance->getId() ?? 0;
        if ($instanceId <= 0) {
            return null;
        }

        $conflictTypes = [
            'instance.reinstall',
            'instance.backup.create',
            'instance.backup.restore',
            'instance.config.apply',
            'instance.settings.update',
            'instance.addon.install',
            'instance.addon.update',
            'instance.addon.remove',
            'sniper.update',
        ];

        $actionType = match ($action) {
            InstanceScheduleAction::Start => 'instance.start',
            InstanceScheduleAction::Stop => 'instance.stop',
            InstanceScheduleAction::Restart => 'instance.restart',
            InstanceScheduleAction::Update => 'sniper.update',
        };
        $conflictTypes[] = $actionType;

        return $this->jobRepository->findLatestActiveByTypesAndInstanceId(array_values(array_unique($conflictTypes)), $instanceId);
    }

    private function resolveBackupTargetId(BackupDefinition $definition, \App\Module\Core\Domain\Entity\BackupSchedule $schedule): ?string
    {
        if ($schedule->getBackupTarget() instanceof \App\Module\Core\Domain\Entity\BackupTarget) {
            return (string) $schedule->getBackupTarget()->getId();
        }

        if ($definition->getBackupTarget() instanceof \App\Module\Core\Domain\Entity\BackupTarget) {
            return (string) $definition->getBackupTarget()->getId();
        }

        $settings = $this->appSettingsService->getSettings();
        $defaultTargetId = $settings[AppSettingsService::KEY_BACKUP_DEFAULT_TARGET_ID] ?? null;
        if (!is_scalar($defaultTargetId) || !is_numeric((string) $defaultTargetId)) {
            return null;
        }

        $target = $this->backupTargetRepository->find((int) $defaultTargetId);
        if ($target === null || !$target->isEnabled() || $target->getCustomer()->getId() !== $definition->getCustomer()->getId()) {
            return null;
        }

        return (string) $target->getId();
    }
}
