<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application;

use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\DiskEnforcementService;
use App\Module\Core\Application\JobLogger;
use App\Module\Core\Domain\Entity\Backup;
use App\Module\Core\Domain\Entity\BackupDefinition;
use App\Module\Core\Domain\Entity\BackupSchedule;
use App\Module\Core\Domain\Entity\BackupTarget;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\ScheduledTaskRun;
use App\Module\Core\Domain\Enum\BackupStatus;
use App\Module\Core\Domain\Enum\BackupTargetType;
use App\Module\Core\Domain\Enum\InstanceStatus;
use App\Repository\BackupScheduleRepository;
use App\Repository\BackupTargetRepository;
use App\Repository\InstanceRepository;
use App\Repository\JobRepository;
use Cron\CronExpression;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class GameserverBackupScheduleRunner
{
    private const BATCH_SIZE = 250;

    /**
     * @var list<string>
     */
    private const BLOCKING_JOB_TYPES = [
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
    ];

    public function __construct(
        private readonly BackupScheduleRepository $backupScheduleRepository,
        private readonly InstanceRepository $instanceRepository,
        private readonly DiskEnforcementService $diskEnforcementService,
        private readonly JobLogger $jobLogger,
        private readonly AuditLogger $auditLogger,
        private readonly EntityManagerInterface $entityManager,
        private readonly JobRepository $jobRepository,
        private readonly BackupTargetRepository $backupTargetRepository,
        private readonly AppSettingsService $appSettingsService,
        private readonly LoggerInterface $logger,
        #[Autowire('%app.windows_nodes_enabled%')]
        private readonly bool $windowsNodesEnabled,
    ) {
    }

    /** @var list<string> */
    private array $lastCreatedJobIds = [];

    /** @return list<string> */
    public function getLastCreatedJobIds(): array
    {
        return $this->lastCreatedJobIds;
    }

    public function runDue(?\DateTimeImmutable $now = null): int
    {
        $now ??= new \DateTimeImmutable();
        $this->lastCreatedJobIds = [];
        $queued = 0;
        $lastBackupScheduleId = 0;

        do {
            $backupSchedules = $this->backupScheduleRepository->findEnabledBatchAfterId($lastBackupScheduleId, self::BATCH_SIZE);
            foreach ($backupSchedules as $schedule) {
                $lastBackupScheduleId = max($lastBackupScheduleId, (int) ($schedule->getId() ?? 0));
                $queued += $this->queueScheduleIfDue($schedule, $now);
            }
        } while (count($backupSchedules) === self::BATCH_SIZE);

        $this->entityManager->flush();

        return $queued;
    }

    public function runScheduleNow(BackupSchedule $schedule, ?\DateTimeImmutable $now = null): int
    {
        $this->lastCreatedJobIds = [];

        return $this->queueScheduleIfDue($schedule, $now ?? new \DateTimeImmutable(), true);
    }

    private function queueScheduleIfDue(BackupSchedule $schedule, \DateTimeImmutable $now, bool $force = false): int
    {
        $definition = $schedule->getDefinition();
        if ($definition->getTargetType() !== BackupTargetType::Game) {
            return 0;
        }

        $instanceId = (int) $definition->getTargetId();
        $instance = $this->instanceRepository->find($instanceId);
        if (!$instance instanceof Instance) {
            $this->markSkipped($schedule, $now, 'instance_not_found', ['definition_id' => $definition->getId()]);
            return 0;
        }

        $dueWindow = $this->resolveDueWindow($schedule, $now);
        if ($dueWindow === null) {
            return 0;
        }

        [$previousRun, $timeZone] = $dueWindow;
        $lastQueuedAt = $schedule->getLastQueuedAt();
        if (!$force && $lastQueuedAt !== null && $lastQueuedAt->setTimezone($timeZone) >= $previousRun) {
            return 0;
        }

        if (!$this->isBackupEligibleInstance($instance)) {
            $this->markSkippedForInstance($schedule, $instance, $now, 'instance_not_active');
            return 0;
        }

        if (!$instance->getNode()->isActive()) {
            $this->markSkippedForInstance($schedule, $instance, $now, 'agent_offline_or_unknown');
            return 0;
        }

        if ($this->isWindowsInstance($instance) && !$this->windowsNodesEnabled) {
            $this->markSkippedForInstance($schedule, $instance, $now, 'windows_nodes_disabled');
            return 0;
        }

        $activeJob = $this->jobRepository->findLatestActiveByTypesAndInstanceId(self::BLOCKING_JOB_TYPES, $instance->getId() ?? 0);
        if ($activeJob instanceof Job) {
            $this->markSkippedForInstance($schedule, $instance, $now, 'backup_action_in_progress', [
                'blocking_job_id' => $activeJob->getId(),
                'blocking_job_type' => $activeJob->getType(),
            ]);
            return 0;
        }

        if ($this->diskEnforcementService->guardInstanceAction($instance, $now) !== null) {
            $this->markSkippedForInstance($schedule, $instance, $now, 'disk_quota_exceeded', [], 'blocked');
            return 0;
        }

        $backup = new Backup($definition, BackupStatus::Queued);
        $this->entityManager->persist($backup);
        $this->entityManager->flush();

        try {
            $targetId = $this->resolveBackupTargetId($definition, $schedule);
            $job = $this->queueBackupJob($instance, $definition, $schedule, $backup, $targetId);
            $backup->setJob($job);
            $this->entityManager->persist($backup);
            $schedule->setLastQueuedAt($now);
            $schedule->markRun($now, 'queued');
            $this->entityManager->persist($schedule);
            $this->jobLogger->log($job, 'Scheduled gameserver backup queued.', 0);
            $this->auditLogger->log(null, 'instance.backup.schedule_queued', [
                'instance_id' => $instance->getId(),
                'customer_id' => $instance->getCustomer()->getId(),
                'definition_id' => $definition->getId(),
                'backup_id' => $backup->getId(),
                'cron_expression' => $schedule->getCronExpression(),
                'time_zone' => $schedule->getTimeZone(),
                'job_id' => $job->getId(),
                'backup_target_id' => $targetId,
            ]);
            $this->logger->info('gameserver.backup.schedule_queued', [
                'schedule_id' => $schedule->getId(),
                'definition_id' => $definition->getId(),
                'instance_id' => $instance->getId(),
                'job_id' => $job->getId(),
            ]);
            $this->lastCreatedJobIds[] = $job->getId();
            $this->recordHistory($schedule, 'success', 'Queued scheduled gameserver backup.', [$job->getId()]);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            $this->entityManager->clear();
            $this->logger->info('gameserver.backup.schedule_duplicate_suppressed', [
                'schedule_id' => $schedule->getId(),
                'definition_id' => $definition->getId(),
                'instance_id' => $instance->getId(),
            ]);
            return 0;
        }

        return 1;
    }

    /**
     * @return array{0: \DateTimeInterface, 1: \DateTimeZone}|null
     */
    private function resolveDueWindow(BackupSchedule $schedule, \DateTimeImmutable $now): ?array
    {
        $cronExpression = $schedule->getCronExpression();
        if (!CronExpression::isValidExpression($cronExpression)) {
            $this->markSkipped($schedule, $now, 'backup_schedule_invalid');
            return null;
        }

        $scheduleTz = $schedule->getTimeZone() !== '' ? $schedule->getTimeZone() : 'UTC';
        try {
            $timeZone = new \DateTimeZone($scheduleTz);
        } catch (\Throwable) {
            $this->markSkipped($schedule, $now, 'backup_schedule_invalid_timezone');
            return null;
        }

        $cron = CronExpression::factory($cronExpression);
        $previousRun = $cron->getPreviousRunDate($now->setTimezone($timeZone), 0, true);

        return [$previousRun, $timeZone];
    }

    private function isBackupEligibleInstance(Instance $instance): bool
    {
        return in_array($instance->getStatus(), [InstanceStatus::Running, InstanceStatus::Stopped], true);
    }

    private function isWindowsInstance(Instance $instance): bool
    {
        $stats = $instance->getNode()->getLastHeartbeatStats();
        if (is_array($stats) && strtolower((string) ($stats['os'] ?? '')) === 'windows') {
            return true;
        }

        return in_array('windows', $instance->getTemplate()->getSupportedOs(), true)
            && !in_array('linux', $instance->getTemplate()->getSupportedOs(), true);
    }

    private function queueBackupJob(Instance $instance, BackupDefinition $definition, BackupSchedule $schedule, Backup $backup, ?string $targetId): Job
    {
        $payload = [
            'agent_id' => $instance->getNode()->getId(),
            'node_id' => $instance->getNode()->getId(),
            'instance_id' => (string) ($instance->getId() ?? ''),
            'customer_id' => (string) $instance->getCustomer()->getId(),
            'install_path' => (string) ($instance->getInstallPath() ?? ''),
            'base_dir' => (string) ($instance->getInstanceBaseDir() ?? ''),
            'definition_id' => (string) ($definition->getId() ?? ''),
            'backup_id' => (string) ($backup->getId() ?? ''),
            'retention_days' => (string) $schedule->getRetentionDays(),
            'retention_count' => (string) $schedule->getRetentionCount(),
            'compression' => $schedule->getCompression(),
            'stop_before' => $schedule->isStopBefore() ? 'true' : 'false',
        ];

        if ($targetId !== null) {
            $payload['backup_target_id'] = $targetId;
        }

        $job = new Job('instance.backup.create', $payload);
        $this->entityManager->persist($job);

        return $job;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function markSkippedForInstance(BackupSchedule $schedule, Instance $instance, \DateTimeImmutable $now, string $reason, array $context = [], string $status = 'skipped'): void
    {
        $this->markSkipped($schedule, $now, $reason, array_merge([
            'instance_id' => $instance->getId(),
            'customer_id' => $instance->getCustomer()->getId(),
            'definition_id' => $schedule->getDefinition()->getId(),
        ], $context), $status);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function markSkipped(BackupSchedule $schedule, \DateTimeImmutable $now, string $reason, array $context = [], string $status = 'skipped'): void
    {
        $schedule->markRun($now, $status, $reason);
        $schedule->setLastQueuedAt($now);
        $this->entityManager->persist($schedule);
        $this->auditLogger->log(null, 'instance.backup.schedule_skipped', array_merge([
            'schedule_id' => $schedule->getId(),
            'reason' => $reason,
        ], $context));
        $this->logger->info('gameserver.backup.schedule_skipped', array_merge([
            'schedule_id' => $schedule->getId(),
            'reason' => $reason,
        ], $context));
        $this->recordHistory($schedule, $status === 'blocked' ? 'failed' : 'skipped', $reason);
    }


    /** @param list<string> $createdJobIds */
    private function recordHistory(\App\Module\Core\Domain\Entity\BackupSchedule $schedule, string $status, ?string $message, array $createdJobIds = []): void
    {
        $startedAt = new \DateTimeImmutable();
        $run = new ScheduledTaskRun(
            'backup_schedule',
            (string) ($schedule->getId() ?? ''),
            sprintf('Gameserver Backup #%s', $schedule->getId() ?? '?'),
            'gameserver.auto_backup',
            'gameserver',
            $startedAt,
        );
        $run->finish($status, $message, $createdJobIds, new \DateTimeImmutable());
        $this->entityManager->persist($run);
    }

    private function resolveBackupTargetId(BackupDefinition $definition, BackupSchedule $schedule): ?string
    {
        if ($schedule->getBackupTarget() instanceof BackupTarget) {
            return (string) $schedule->getBackupTarget()->getId();
        }

        if ($definition->getBackupTarget() instanceof BackupTarget) {
            return (string) $definition->getBackupTarget()->getId();
        }

        $settings = $this->appSettingsService->getSettings();
        $defaultTargetId = $settings[AppSettingsService::KEY_BACKUP_DEFAULT_TARGET_ID] ?? null;
        if (!is_scalar($defaultTargetId) || !is_numeric((string) $defaultTargetId)) {
            return null;
        }

        $target = $this->backupTargetRepository->find((int) $defaultTargetId);
        if (!$target instanceof BackupTarget || !$target->isEnabled()) {
            return null;
        }

        if (!$target->getCustomer()->isAdmin() && $target->getCustomer()->getId() !== $definition->getCustomer()->getId()) {
            return null;
        }

        return (string) $target->getId();
    }
}
