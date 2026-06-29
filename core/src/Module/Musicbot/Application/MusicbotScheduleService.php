<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

use App\Module\AgentOrchestrator\Domain\Entity\AgentJob;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Entity\MusicbotSchedule;
use App\Module\Musicbot\Domain\Enum\MusicbotScheduleAction;
use App\Repository\MusicbotScheduleRepository;
use Cron\CronExpression;
use Doctrine\ORM\EntityManagerInterface;

final class MusicbotScheduleService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MusicbotScheduleRepository $scheduleRepository,
        private readonly MusicbotScheduleDispatcherInterface $scheduleDispatcher,
        private readonly MusicbotRuntimeEventService $runtimeEventService,
        private readonly AuditLogger $auditLogger,
        private readonly MusicbotQuotaService $quotaService,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @throws \InvalidArgumentException on bad cron/timezone
     */
    public function create(
        User $customer,
        MusicbotInstance $instance,
        string $name,
        string $cronExpression,
        string $timezone,
        bool $enabled,
        MusicbotScheduleAction $action,
        array $payload,
    ): MusicbotSchedule {
        $this->assertCustomerOwnsInstance($customer, $instance);
        $this->validateCronExpression($cronExpression);
        $this->validateTimezone($timezone);
        $this->quotaService->assertSchedulerAllowed($customer);

        $schedule = new MusicbotSchedule(
            $customer,
            $instance,
            $name,
            $cronExpression,
            $timezone ?: null,
            $enabled,
            $action,
            $payload !== [] ? $payload : null,
        );
        $schedule->setNextRunAt($this->calcNextRunAt($cronExpression, $timezone));

        $this->entityManager->persist($schedule);
        $this->entityManager->flush();

        $this->runtimeEventService->record($instance, 'schedule.created', 'info', sprintf('Schedule "%s" created.', $name), ['action' => $action->value, 'cron' => $cronExpression]);
        $this->auditLogger->log($customer, 'musicbot.schedule_created', ['instance_id' => $instance->getId(), 'schedule_id' => $schedule->getId(), 'action' => $action->value]);

        return $schedule;
    }

    /**
     * @param array<string, mixed> $data
     * @throws \InvalidArgumentException on bad cron/timezone or ownership mismatch
     */
    public function update(User $customer, MusicbotSchedule $schedule, array $data): MusicbotSchedule
    {
        $this->assertOwnership($customer, $schedule);

        $name = trim((string) ($data['name'] ?? $schedule->getName()));
        $cronExpression = trim((string) ($data['cron_expression'] ?? $schedule->getCronExpression()));
        $timezone = trim((string) ($data['timezone'] ?? $schedule->getTimezone() ?? 'UTC'));
        $enabled = isset($data['enabled']) ? (bool) $data['enabled'] : $schedule->isEnabled();
        $action = isset($data['action'])
            ? MusicbotScheduleAction::from((string) $data['action'])
            : $schedule->getAction();
        $payload = array_key_exists('payload', $data) ? (array) $data['payload'] : ($schedule->getPayload() ?? []);

        $this->validateCronExpression($cronExpression);
        $this->validateTimezone($timezone);

        $schedule->update($name, $cronExpression, $timezone ?: null, $enabled, $action, $payload !== [] ? $payload : null);
        $schedule->setNextRunAt($this->calcNextRunAt($cronExpression, $timezone));
        $this->entityManager->flush();

        $this->runtimeEventService->record($schedule->getInstance(), 'schedule.updated', 'info', sprintf('Schedule "%s" updated.', $name), ['action' => $action->value]);
        $this->auditLogger->log($customer, 'musicbot.schedule_updated', ['schedule_id' => $schedule->getId(), 'action' => $action->value]);

        return $schedule;
    }

    public function delete(User $customer, MusicbotSchedule $schedule): void
    {
        $this->assertOwnership($customer, $schedule);

        $instance = $schedule->getInstance();
        $scheduleId = $schedule->getId();
        $scheduleName = $schedule->getName();

        $this->entityManager->remove($schedule);
        $this->entityManager->flush();

        $this->runtimeEventService->record($instance, 'schedule.deleted', 'info', sprintf('Schedule "%s" deleted.', $scheduleName));
        $this->auditLogger->log($customer, 'musicbot.schedule_deleted', ['instance_id' => $instance->getId(), 'schedule_id' => $scheduleId]);
    }

    public function toggle(User $customer, MusicbotSchedule $schedule, bool $enabled): void
    {
        $this->assertOwnership($customer, $schedule);
        $schedule->setEnabled($enabled);
        if ($enabled) {
            $schedule->setNextRunAt($this->calcNextRunAt($schedule->getCronExpression(), $schedule->getTimezone() ?? 'UTC'));
        }
        $this->entityManager->flush();

        $this->runtimeEventService->record($schedule->getInstance(), 'schedule.updated', 'info', sprintf('Schedule "%s" %s.', $schedule->getName(), $enabled ? 'enabled' : 'disabled'));
    }

    /**
     * Dispatch the job for a specific schedule immediately (manual test / run-now).
     */
    public function runNow(User $customer, MusicbotSchedule $schedule): AgentJob
    {
        $this->assertOwnership($customer, $schedule);

        $job = $this->dispatchScheduleJob($schedule);

        $this->runtimeEventService->record($schedule->getInstance(), 'schedule.executed', 'info', sprintf('Schedule "%s" triggered manually.', $schedule->getName()), ['action' => $schedule->getAction()->value, 'job_id' => $job->getId()]);
        $this->auditLogger->log($customer, 'musicbot.schedule_run_now', ['schedule_id' => $schedule->getId(), 'job_id' => $job->getId()]);

        return $job;
    }

    /**
     * Run all due schedules. Called from the scheduler handler.
     *
     * @return list<string> created job IDs
     */
    public function runDue(\DateTimeImmutable $now): array
    {
        $schedules = $this->scheduleRepository->findDue($now);
        $jobIds = [];

        foreach ($schedules as $schedule) {
            try {
                $job = $this->dispatchScheduleJob($schedule);
                $schedule->markExecuted($now, $this->calcNextRunAt($schedule->getCronExpression(), $schedule->getTimezone() ?? 'UTC'));
                $this->runtimeEventService->record($schedule->getInstance(), 'schedule.executed', 'info', sprintf('Schedule "%s" executed.', $schedule->getName()), ['action' => $schedule->getAction()->value, 'job_id' => $job->getId()]);
                $jobIds[] = $job->getId();
            } catch (\Throwable $e) {
                $schedule->markFailed($now, $e->getMessage(), $this->calcNextRunAt($schedule->getCronExpression(), $schedule->getTimezone() ?? 'UTC'));
                $this->runtimeEventService->record($schedule->getInstance(), 'schedule.failed', 'error', sprintf('Schedule "%s" failed: %s', $schedule->getName(), $e->getMessage()), ['action' => $schedule->getAction()->value]);
            }
        }

        if ($schedules !== []) {
            $this->entityManager->flush();
        }

        return $jobIds;
    }

    public function calcNextRunAt(string $cronExpression, string $timezone): ?\DateTimeImmutable
    {
        if (!CronExpression::isValidExpression($cronExpression)) {
            return null;
        }

        try {
            $tz = new \DateTimeZone($timezone ?: 'UTC');
            $cron = CronExpression::factory($cronExpression);
            $next = $cron->getNextRunDate(new \DateTimeImmutable('now', $tz), 0, true);

            return \DateTimeImmutable::createFromMutable($next)->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }

    public function normalize(MusicbotSchedule $schedule): array
    {
        return [
            'id' => $schedule->getId(),
            'name' => $schedule->getName(),
            'cron_expression' => $schedule->getCronExpression(),
            'timezone' => $schedule->getTimezone() ?? 'UTC',
            'enabled' => $schedule->isEnabled(),
            'action' => $schedule->getAction()->value,
            'payload' => $schedule->getPayload() ?? [],
            'last_run_at' => $schedule->getLastRunAt()?->format(\DateTimeInterface::ATOM),
            'next_run_at' => $schedule->getNextRunAt()?->format(\DateTimeInterface::ATOM),
            'last_error' => $schedule->getLastError(),
            'created_at' => $schedule->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $schedule->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            'instance_id' => $schedule->getInstance()->getId(),
        ];
    }

    public function dispatchScheduleJobPublic(MusicbotSchedule $schedule): AgentJob
    {
        return $this->scheduleDispatcher->dispatch($schedule);
    }

    private function dispatchScheduleJob(MusicbotSchedule $schedule): AgentJob
    {
        return $this->scheduleDispatcher->dispatch($schedule);
    }

    private function validateCronExpression(string $cronExpression): void
    {
        if (!CronExpression::isValidExpression($cronExpression)) {
            throw new \InvalidArgumentException(sprintf('Invalid cron expression: "%s".', $cronExpression));
        }
    }

    private function validateTimezone(string $timezone): void
    {
        if ($timezone === '' || $timezone === 'UTC') {
            return;
        }

        try {
            new \DateTimeZone($timezone);
        } catch (\Throwable) {
            throw new \InvalidArgumentException(sprintf('Invalid timezone: "%s".', $timezone));
        }
    }

    private function assertCustomerOwnsInstance(User $customer, MusicbotInstance $instance): void
    {
        if ($instance->getCustomer()->getId() !== $customer->getId()) {
            throw new \RuntimeException('Musicbot instance does not belong to the current customer.');
        }
    }

    private function assertOwnership(User $customer, MusicbotSchedule $schedule): void
    {
        if ($schedule->getCustomer()->getId() !== $customer->getId()) {
            throw new \RuntimeException('Schedule does not belong to the current customer.');
        }
    }
}
