<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Scheduler;

use App\Module\Core\Domain\Entity\ScheduledTaskRun;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class CentralSchedulerRunner
{
    public function __construct(
        private readonly ScheduleHandlerRegistry $registry,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function runDue(?\DateTimeImmutable $now = null): int
    {
        $now ??= new \DateTimeImmutable();
        $created = 0;

        foreach ($this->registry->all() as $handler) {
            $startedAt = new \DateTimeImmutable();
            $run = new ScheduledTaskRun('handler', $handler->type(), $handler->type(), $handler->type(), strtok($handler->type(), '.') ?: 'core', $startedAt);
            $this->entityManager->persist($run);

            try {
                $result = $handler->runDue($now);
            } catch (\Throwable $exception) {
                $result = ScheduleExecutionResult::failed($exception->getMessage());
                $this->logger->error('scheduler.handler_failed', ['type' => $handler->type(), 'exception' => $exception]);
            }

            $run->finish($result->status, $result->message, $result->createdJobIds, new \DateTimeImmutable());
            $created += count($result->createdJobIds);
            $this->entityManager->persist($run);
        }

        $this->entityManager->flush();

        return $created;
    }

    public function runNow(string $type, string $source, string $id, ?\DateTimeImmutable $now = null): ScheduleExecutionResult
    {
        $handler = $this->registry->get($type);
        if ($handler === null) {
            return ScheduleExecutionResult::failed('Unknown schedule handler.');
        }

        $now ??= new \DateTimeImmutable();
        $startedAt = new \DateTimeImmutable();
        $run = new ScheduledTaskRun($source, $id, $type, $type, strtok($type, '.') ?: 'core', $startedAt);
        $this->entityManager->persist($run);

        try {
            $result = $handler->runNow($source, $id, $now);
        } catch (\Throwable $exception) {
            $result = ScheduleExecutionResult::failed($exception->getMessage());
            $this->logger->error('scheduler.run_now_failed', ['type' => $type, 'source' => $source, 'id' => $id, 'exception' => $exception]);
        }

        $run->finish($result->status, $result->message, $result->createdJobIds, new \DateTimeImmutable());
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        return $result;
    }
}
