<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Instance;
use App\Entity\Backup;
use App\Entity\Job;
use App\Enum\BackupStatus;
use App\Enum\BackupTargetType;
use App\Enum\InstanceScheduleAction;
use App\Enum\InstanceUpdatePolicy;
use App\Repository\BackupScheduleRepository;
use App\Repository\InstanceRepository;
use App\Repository\InstanceScheduleRepository;
use App\Service\AuditLogger;
use App\Service\DiskEnforcementService;
use App\Service\InstanceJobPayloadBuilder;
use App\Service\JobLogger;
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
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = new \DateTimeImmutable();
        $queued = 0;

        $instanceSchedules = $this->instanceScheduleRepository->findBy(['enabled' => true], ['id' => 'ASC'], self::DEFAULT_BATCH_SIZE);
        foreach ($instanceSchedules as $schedule) {
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

            if ($this->diskEnforcementService->guardInstanceAction($instance, $now) !== null) {
                continue;
            }

            $job = match ($action) {
                InstanceScheduleAction::Update => $this->queueUpdateJob($instance, $now),
                InstanceScheduleAction::Start => $this->queueInstanceJob('instance.start', $instance),
                InstanceScheduleAction::Stop => $this->queueInstanceJob('instance.stop', $instance),
                InstanceScheduleAction::Restart => $this->queueInstanceJob('instance.restart', $instance),
            };

            $schedule->setLastQueuedAt($now);
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

        $backupSchedules = $this->backupScheduleRepository->findBy(['enabled' => true], ['id' => 'ASC'], self::DEFAULT_BATCH_SIZE);
        foreach ($backupSchedules as $schedule) {
            $definition = $schedule->getDefinition();
            if ($definition->getTargetType() !== BackupTargetType::Game) {
                continue;
            }

            $instanceId = (int) $definition->getTargetId();
            $instance = $this->instanceRepository->find($instanceId);
            if ($instance === null) {
                continue;
            }

            $cronExpression = $schedule->getCronExpression();
            if (!CronExpression::isValidExpression($cronExpression)) {
                continue;
            }

            $cron = CronExpression::factory($cronExpression);
            $previousRun = $cron->getPreviousRunDate($now, 0, true);
            $lastQueuedAt = $schedule->getLastQueuedAt();
            if ($lastQueuedAt !== null && $lastQueuedAt >= $previousRun) {
                continue;
            }

            if ($this->diskEnforcementService->guardInstanceAction($instance, $now) !== null) {
                continue;
            }

            $backup = new Backup($definition, BackupStatus::Queued);
            $this->entityManager->persist($backup);
            $this->entityManager->flush();

            $job = $this->queueInstanceJob('instance.backup.create', $instance, [
                'definition_id' => $definition->getId(),
                'backup_id' => $backup->getId(),
                'retention_days' => (string) $schedule->getRetentionDays(),
                'retention_count' => (string) $schedule->getRetentionCount(),
            ]);
            $backup->setJob($job);
            $this->entityManager->persist($backup);
            $schedule->setLastQueuedAt($now);
            $this->entityManager->persist($schedule);
            $this->jobLogger->log($job, 'Scheduled backup queued.', 0);

            $this->auditLogger->log(null, 'instance.backup.schedule_queued', [
                'instance_id' => $instance->getId(),
                'customer_id' => $instance->getCustomer()->getId(),
                'definition_id' => $definition->getId(),
                'backup_id' => $backup->getId(),
                'cron_expression' => $cronExpression,
                'job_id' => $job->getId(),
            ]);
            $queued++;
        }

        $this->entityManager->flush();

        $output->writeln(sprintf('Queued %d scheduled job(s).', $queued));

        return Command::SUCCESS;
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
        $this->entityManager->persist($instance);

        return $job;
    }
}
