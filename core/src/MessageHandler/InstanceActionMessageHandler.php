<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Backup;
use App\Entity\BackupDefinition;
use App\Entity\Instance;
use App\Entity\InstanceSchedule;
use App\Entity\Job;
use App\Entity\JobResult;
use App\Enum\BackupStatus;
use App\Enum\InstanceScheduleAction;
use App\Enum\InstanceUpdatePolicy;
use App\Enum\JobResultStatus;
use App\Enum\JobStatus;
use App\Message\InstanceActionMessage;
use App\Repository\BackupDefinitionRepository;
use App\Repository\BackupRepository;
use App\Repository\InstanceRepository;
use App\Repository\InstanceScheduleRepository;
use App\Repository\UserRepository;
use App\Service\AuditLogger;
use App\Service\JobLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class InstanceActionMessageHandler
{
    public function __construct(
        private readonly InstanceRepository $instanceRepository,
        private readonly InstanceScheduleRepository $instanceScheduleRepository,
        private readonly BackupDefinitionRepository $backupDefinitionRepository,
        private readonly BackupRepository $backupRepository,
        private readonly UserRepository $userRepository,
        private readonly AuditLogger $auditLogger,
        private readonly JobLogger $jobLogger,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(InstanceActionMessage $message): array
    {
        $action = $message->getAction();
        $payload = $message->getPayload();
        $actor = $this->userRepository->find($message->getActorId());
        $instance = $this->instanceRepository->find($message->getInstanceId());

        if ($instance === null) {
            throw new \RuntimeException('Instance not found.');
        }

        return match ($action) {
            'instance.settings.update' => $this->handleSettingsUpdate($instance, $actor, $payload),
            'instance.schedule.update' => $this->handleScheduleUpdate($instance, $actor, $payload),
            'instance.backup.create' => $this->handleBackupCreate($instance, $actor, $payload),
            'instance.backup.restore' => $this->handleBackupRestore($instance, $actor, $payload),
            default => $this->handleAgentJob($instance, $actor, $action, $payload),
        };
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function handleSettingsUpdate(Instance $instance, ?\App\Entity\User $actor, array $payload): array
    {
        $policy = InstanceUpdatePolicy::from((string) ($payload['update_policy'] ?? InstanceUpdatePolicy::Manual->value));
        $lockedBuildId = $this->stringOrNull($payload['locked_build_id'] ?? null);
        $lockedVersion = $this->stringOrNull($payload['locked_version'] ?? null);
        $cronExpression = (string) ($payload['cron_expression'] ?? '');
        $timeZone = (string) ($payload['time_zone'] ?? 'UTC');

        $instance->setUpdatePolicy($policy);
        $instance->setLockedBuildId($lockedBuildId);
        $instance->setLockedVersion($lockedVersion);
        $this->entityManager->persist($instance);

        $schedule = $this->instanceScheduleRepository->findOneByInstanceAndAction($instance, InstanceScheduleAction::Update);
        if ($policy === InstanceUpdatePolicy::Auto) {
            if ($schedule === null) {
                $schedule = new InstanceSchedule(
                    $instance,
                    $instance->getCustomer(),
                    InstanceScheduleAction::Update,
                    $cronExpression,
                    $timeZone,
                    true,
                );
            } else {
                $schedule->update(InstanceScheduleAction::Update, $cronExpression, $timeZone, true);
            }
            $this->entityManager->persist($schedule);
        } elseif ($schedule !== null) {
            $schedule->update(InstanceScheduleAction::Update, $schedule->getCronExpression(), $schedule->getTimeZone(), false);
            $this->entityManager->persist($schedule);
        }

        $job = new Job('instance.settings.update', $payload);
        $this->entityManager->persist($job);
        $this->jobLogger->log($job, 'Settings update queued.', 0);
        $this->completeJob($job, ['message' => 'Settings updated.']);

        $this->auditLogger->log($actor, 'instance.update.settings_updated', [
            'instance_id' => $instance->getId(),
            'customer_id' => $instance->getCustomer()->getId(),
            'policy' => $policy->value,
            'locked_build_id' => $instance->getLockedBuildId(),
            'locked_version' => $instance->getLockedVersion(),
            'cron_expression' => $schedule?->getCronExpression(),
            'time_zone' => $schedule?->getTimeZone(),
            'schedule_enabled' => $schedule?->isEnabled(),
            'job_id' => $job->getId(),
        ]);

        $this->entityManager->flush();

        return [
            'job_id' => $job->getId(),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function handleScheduleUpdate(Instance $instance, ?\App\Entity\User $actor, array $payload): array
    {
        $action = InstanceScheduleAction::from((string) $payload['action']);
        $cronExpression = (string) ($payload['cron_expression'] ?? '');
        $timeZone = (string) ($payload['time_zone'] ?? 'UTC');
        $enabled = (bool) ($payload['enabled'] ?? true);

        $schedule = $this->instanceScheduleRepository->findOneByInstanceAndAction($instance, $action);
        if ($enabled) {
            if ($schedule === null) {
                $schedule = new InstanceSchedule(
                    $instance,
                    $instance->getCustomer(),
                    $action,
                    $cronExpression,
                    $timeZone,
                    true,
                );
            } else {
                $schedule->update($action, $cronExpression, $timeZone, true);
            }
            $this->entityManager->persist($schedule);
        } elseif ($schedule !== null) {
            $schedule->update($action, $schedule->getCronExpression(), $schedule->getTimeZone(), false);
            $this->entityManager->persist($schedule);
        }

        $job = new Job('instance.schedule.update', $payload);
        $this->entityManager->persist($job);
        $this->jobLogger->log($job, 'Schedule update queued.', 0);
        $this->completeJob($job, ['message' => 'Schedule updated.']);

        $this->auditLogger->log($actor, 'instance.schedule.updated', [
            'instance_id' => $instance->getId(),
            'customer_id' => $instance->getCustomer()->getId(),
            'action' => $action->value,
            'cron_expression' => $schedule?->getCronExpression(),
            'time_zone' => $schedule?->getTimeZone(),
            'enabled' => $schedule?->isEnabled(),
            'job_id' => $job->getId(),
        ]);

        $this->entityManager->flush();

        return [
            'job_id' => $job->getId(),
            'schedule_id' => $schedule?->getId(),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function handleBackupCreate(Instance $instance, ?\App\Entity\User $actor, array $payload): array
    {
        $definitionId = $payload['definition_id'] ?? null;
        if (!is_int($definitionId) && !is_string($definitionId)) {
            throw new \RuntimeException('Backup definition is required.');
        }

        $definition = $this->backupDefinitionRepository->find((int) $definitionId);
        if (!$definition instanceof BackupDefinition) {
            throw new \RuntimeException('Backup definition not found.');
        }

        $backup = new Backup($definition, BackupStatus::Queued);
        $this->entityManager->persist($backup);
        $this->entityManager->flush();

        $jobPayload = array_merge($payload, [
            'backup_id' => $backup->getId(),
        ]);

        $job = new Job('instance.backup.create', $jobPayload);
        $this->entityManager->persist($job);
        $backup->setJob($job);
        $this->entityManager->persist($backup);
        $this->jobLogger->log($job, 'Backup queued.', 0);

        $this->auditLogger->log($actor, 'instance.backup.queued', [
            'instance_id' => $instance->getId(),
            'customer_id' => $instance->getCustomer()->getId(),
            'backup_id' => $backup->getId(),
            'definition_id' => $definition->getId(),
            'job_id' => $job->getId(),
        ]);

        $this->entityManager->flush();

        return [
            'job_id' => $job->getId(),
            'backup_id' => $backup->getId(),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function handleBackupRestore(Instance $instance, ?\App\Entity\User $actor, array $payload): array
    {
        $backupId = $payload['backup_id'] ?? null;
        if (!is_int($backupId) && !is_string($backupId)) {
            throw new \RuntimeException('Backup id is required.');
        }

        $backup = $this->backupRepository->find((int) $backupId);
        if (!$backup instanceof Backup) {
            throw new \RuntimeException('Backup not found.');
        }

        $job = new Job('instance.backup.restore', $payload);
        $this->entityManager->persist($job);
        $this->jobLogger->log($job, 'Restore queued.', 0);

        $this->auditLogger->log($actor, 'instance.backup.restore_queued', [
            'instance_id' => $instance->getId(),
            'customer_id' => $instance->getCustomer()->getId(),
            'backup_id' => $backup->getId(),
            'job_id' => $job->getId(),
        ]);

        $this->entityManager->flush();

        return [
            'job_id' => $job->getId(),
            'backup_id' => $backup->getId(),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function handleAgentJob(Instance $instance, ?\App\Entity\User $actor, string $action, array $payload): array
    {
        $job = new Job($action, $payload);
        $this->entityManager->persist($job);
        $this->jobLogger->log($job, 'Job queued.', 0);

        $this->auditLogger->log($actor, sprintf('%s.queued', $action), [
            'instance_id' => $instance->getId(),
            'customer_id' => $instance->getCustomer()->getId(),
            'job_id' => $job->getId(),
        ]);

        $this->entityManager->flush();

        return [
            'job_id' => $job->getId(),
        ];
    }

    private function completeJob(Job $job, array $output): void
    {
        $job->transitionTo(JobStatus::Running);
        $jobResult = new JobResult($job, JobResultStatus::Succeeded, $output, new \DateTimeImmutable());
        $job->transitionTo(JobStatus::Succeeded);
        $job->attachResult($jobResult);
        $this->entityManager->persist($jobResult);
        $this->jobLogger->log($job, 'Completed.', 100);
    }

    private function stringOrNull(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
