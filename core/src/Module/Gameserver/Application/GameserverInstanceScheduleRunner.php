<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\DiskEnforcementService;
use App\Module\Core\Application\JobLogger;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\InstanceSchedule;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\ScheduledTaskRun;
use App\Module\Core\Domain\Enum\InstanceScheduleAction;
use App\Module\Core\Domain\Enum\InstanceStatus;
use App\Repository\InstanceScheduleRepository;
use App\Repository\JobRepository;
use Cron\CronExpression;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class GameserverInstanceScheduleRunner
{
    private const BATCH_SIZE = 250;

    /**
     * @var list<string>
     */
    private const BLOCKING_JOB_TYPES = [
        'instance.restart',
        'instance.reinstall',
        'instance.backup.create',
        'instance.backup.restore',
        'instance.start',
        'instance.stop',
        'sniper.update',
        'instance.config.apply',
        'instance.settings.update',
        'instance.addon.install',
        'instance.addon.update',
        'instance.addon.remove',
    ];

    public function __construct(
        private readonly InstanceScheduleRepository $instanceScheduleRepository,
        private readonly DiskEnforcementService $diskEnforcementService,
        private readonly JobLogger $jobLogger,
        private readonly AuditLogger $auditLogger,
        private readonly EntityManagerInterface $entityManager,
        private readonly JobRepository $jobRepository,
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
        $lastScheduleId = 0;

        do {
            $schedules = $this->instanceScheduleRepository->findEnabledBatchAfterId($lastScheduleId, self::BATCH_SIZE);
            foreach ($schedules as $schedule) {
                $lastScheduleId = max($lastScheduleId, (int) ($schedule->getId() ?? 0));
                $queued += $this->queueScheduleIfDue($schedule, $now);
            }
        } while (count($schedules) === self::BATCH_SIZE);

        $this->entityManager->flush();

        return $queued;
    }

    public function runScheduleNow(InstanceSchedule $schedule, ?\DateTimeImmutable $now = null): int
    {
        $this->lastCreatedJobIds = [];

        return $this->queueScheduleIfDue($schedule, $now ?? new \DateTimeImmutable(), true);
    }

    private function queueScheduleIfDue(InstanceSchedule $schedule, \DateTimeImmutable $now, bool $force = false): int
    {
        if ($schedule->getAction() !== InstanceScheduleAction::Restart) {
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

        $instance = $schedule->getInstance();
        if (!$instance instanceof Instance) {
            $this->markSkipped($schedule, $now, 'instance_not_found');
            return 0;
        }

        if ($instance->getStatus() !== InstanceStatus::Running) {
            $this->markSkipped($schedule, $now, 'restart_requires_running');
            return 0;
        }

        if ($instance->getInstallPath() === null || $instance->getInstallPath() === '') {
            $this->markSkipped($schedule, $now, 'install_path_missing');
            return 0;
        }

        $node = $instance->getNode();
        if (!$node->isActive()) {
            $this->markSkipped($schedule, $now, 'agent_offline_or_unknown');
            return 0;
        }

        if ($this->isWindowsInstance($instance) && !$this->windowsNodesEnabled) {
            $this->markSkipped($schedule, $now, 'windows_nodes_disabled');
            return 0;
        }

        if (!$this->agentCanDispatchRestart($instance)) {
            $this->markSkipped($schedule, $now, 'agent_missing_capability');
            return 0;
        }

        $instanceId = $instance->getId() ?? 0;
        $scheduleId = (string) ($schedule->getId() ?? '');
        if ($this->jobRepository->findLatestActiveByTypeInstanceIdAndScheduleId('instance.restart', $instanceId, $scheduleId) instanceof Job) {
            $this->markSkipped($schedule, $now, 'restart_action_in_progress_for_schedule');
            return 0;
        }

        $activeJob = $this->jobRepository->findLatestActiveByTypesAndInstanceId(self::BLOCKING_JOB_TYPES, $instanceId);
        if ($activeJob instanceof Job) {
            $this->markSkipped($schedule, $now, 'lifecycle_action_in_progress', [
                'blocking_job_id' => $activeJob->getId(),
                'blocking_job_type' => $activeJob->getType(),
            ]);
            return 0;
        }

        if ($this->diskEnforcementService->guardInstanceAction($instance, $now) !== null) {
            $this->markSkipped($schedule, $now, 'disk_quota_exceeded', [], 'blocked');
            return 0;
        }

        $job = $this->queueRestartJob($schedule, $instance);
        $schedule->markQueued($now);
        $this->entityManager->persist($schedule);
        $this->jobLogger->log($job, 'Scheduled gameserver restart queued.', 0);
        $this->auditLogger->log(null, 'instance.restart.schedule_queued', [
            'schedule_id' => $schedule->getId(),
            'instance_id' => $instance->getId(),
            'customer_id' => $instance->getCustomer()->getId(),
            'job_id' => $job->getId(),
            'cron_expression' => $schedule->getCronExpression(),
            'time_zone' => $schedule->getTimeZone() ?? 'UTC',
        ]);
        $this->logger->info('gameserver.instance.schedule_queued', [
            'schedule_id' => $schedule->getId(),
            'instance_id' => $instance->getId(),
            'job_id' => $job->getId(),
            'action' => InstanceScheduleAction::Restart->value,
        ]);
        $this->lastCreatedJobIds[] = $job->getId();
        $this->recordHistory($schedule, 'success', 'Queued scheduled gameserver restart.', [$job->getId()]);
        $this->entityManager->flush();

        return 1;
    }

    /**
     * @return array{0: \DateTimeInterface, 1: \DateTimeZone}|null
     */
    private function resolveDueWindow(InstanceSchedule $schedule, \DateTimeImmutable $now): ?array
    {
        $cronExpression = $schedule->getCronExpression();
        if (!CronExpression::isValidExpression($cronExpression)) {
            $this->markSkipped($schedule, $now, 'schedule_invalid');
            return null;
        }

        $scheduleTz = $schedule->getTimeZone() !== null && $schedule->getTimeZone() !== '' ? $schedule->getTimeZone() : 'UTC';
        try {
            $timeZone = new \DateTimeZone($scheduleTz);
        } catch (\Throwable) {
            $this->markSkipped($schedule, $now, 'schedule_invalid_timezone');
            return null;
        }

        $cron = CronExpression::factory($cronExpression);
        $previousRun = $cron->getPreviousRunDate($now->setTimezone($timeZone), 0, true);

        return [$previousRun, $timeZone];
    }

    private function queueRestartJob(InstanceSchedule $schedule, Instance $instance): Job
    {
        $payload = [
            'agent_id' => $instance->getNode()->getId(),
            'node_id' => $instance->getNode()->getId(),
            'instance_id' => (string) ($instance->getId() ?? ''),
            'customer_id' => (string) $instance->getCustomer()->getId(),
            'install_path' => (string) $instance->getInstallPath(),
            'base_dir' => (string) ($instance->getInstanceBaseDir() ?? ''),
            'reason' => 'scheduled_restart',
            'schedule_id' => (string) ($schedule->getId() ?? ''),
        ];

        $job = new Job('instance.restart', $payload);
        $this->entityManager->persist($job);

        return $job;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function markSkipped(InstanceSchedule $schedule, \DateTimeImmutable $now, string $reason, array $context = [], string $status = 'skipped'): void
    {
        $schedule->markScheduleResult($status, $reason);
        $schedule->setLastQueuedAt($now);
        $this->entityManager->persist($schedule);

        $instance = $schedule->getInstance();
        $context = array_merge([
            'schedule_id' => $schedule->getId(),
            'instance_id' => $instance->getId(),
            'customer_id' => $instance->getCustomer()->getId(),
            'action' => $schedule->getAction()->value,
            'reason' => $reason,
        ], $context);

        $this->auditLogger->log(null, 'instance.restart.schedule_skipped', $context);
        $this->logger->info('gameserver.instance.schedule_skipped', $context);
        $this->recordHistory($schedule, $status === 'blocked' ? 'failed' : 'skipped', $reason);
    }



    /** @param list<string> $createdJobIds */
    private function recordHistory(\App\Module\Core\Domain\Entity\InstanceSchedule $schedule, string $status, ?string $message, array $createdJobIds = []): void
    {
        $startedAt = new \DateTimeImmutable();
        $run = new ScheduledTaskRun(
            'instance_schedule',
            (string) ($schedule->getId() ?? ''),
            sprintf('Gameserver Restart #%s', $schedule->getId() ?? '?'),
            'gameserver.auto_restart',
            'gameserver',
            $startedAt,
        );
        $run->finish($status, $message, $createdJobIds, new \DateTimeImmutable());
        $this->entityManager->persist($run);
    }

    private function agentCanDispatchRestart(Instance $instance): bool
    {
        $metadata = $instance->getNode()->getMetadata();
        if (!is_array($metadata)) {
            return true;
        }

        $capabilities = $metadata['capabilities'] ?? null;
        if (!is_array($capabilities) || $capabilities === []) {
            return true;
        }

        $normalizedCapabilities = array_values(array_filter(array_map(static fn (mixed $value): string => trim((string) $value), $capabilities)));
        if ($normalizedCapabilities === []) {
            return true;
        }

        return in_array('instance.restart', $normalizedCapabilities, true);
    }

    private function isWindowsInstance(Instance $instance): bool
    {
        $stats = $instance->getNode()->getLastHeartbeatStats();
        if (is_array($stats) && strtolower((string) ($stats['os'] ?? '')) === 'windows') {
            return true;
        }

        $supportedOs = $instance->getTemplate()->getSupportedOs();

        return in_array('windows', $supportedOs, true)
            && !in_array('linux', $supportedOs, true);
    }
}
