<?php

declare(strict_types=1);

namespace App\Module\Core\Command;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Enum\InstanceStatus;
use App\Module\Core\Domain\Enum\JobStatus;
use App\Repository\InstanceRepository;
use App\Repository\JobRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Dispatches instance.watchdog.check agent jobs for all game-server instances
 * that have watchdog monitoring enabled and are in a Running state.
 *
 * Intended to be run every minute via cron or Symfony Scheduler.
 */
#[AsCommand(
    name: 'app:run-watchdog-checks',
    description: 'Dispatch watchdog health-check jobs for game server instances with watchdog enabled.',
)]
final class RunWatchdogChecksCommand extends Command
{
    private const BATCH_SIZE = 200;

    public function __construct(
        private readonly InstanceRepository $instanceRepository,
        private readonly JobRepository $jobRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = new \DateTimeImmutable();
        $dispatched = 0;
        $skipped = 0;
        $lastId = 0;

        do {
            $instances = $this->instanceRepository->findWatchdogEnabledBatchAfterId($lastId, self::BATCH_SIZE);

            foreach ($instances as $instance) {
                $lastId = max($lastId, (int) ($instance->getId() ?? 0));

                // Only check instances that are supposed to be running.
                if ($instance->getStatus() !== InstanceStatus::Running) {
                    $skipped++;
                    continue;
                }

                $node = $instance->getNode();
                if (!$node->isActive()) {
                    $skipped++;
                    continue;
                }

                $instanceId = (string) ($instance->getId() ?? '');

                // Skip if a watchdog job is already queued or running for this instance.
                if ($this->hasActiveWatchdogJob($instanceId)) {
                    $skipped++;
                    continue;
                }

                $serviceName = sprintf('gs-%s', $instanceId);
                $job = new Job('instance.watchdog.check', [
                    'agent_id'    => $node->getId(),
                    'instance_id' => $instanceId,
                    'service_name' => $serviceName,
                    'max_restarts' => 3,
                    'source'      => 'watchdog_scheduler',
                    'triggered_at' => $now->format(DATE_RFC3339),
                ]);
                $this->entityManager->persist($job);

                $this->auditLogger->log(null, 'instance.watchdog.dispatched', [
                    'instance_id' => $instanceId,
                    'agent_id'    => $node->getId(),
                    'job_id'      => $job->getId(),
                ]);

                $dispatched++;

                // Flush in batches to avoid memory pressure.
                if ($dispatched % 50 === 0) {
                    $this->entityManager->flush();
                    $this->entityManager->clear(Job::class);
                }
            }
        } while (count($instances) === self::BATCH_SIZE);

        $this->entityManager->flush();

        $output->writeln(sprintf(
            'Watchdog checks dispatched: %d  skipped: %d',
            $dispatched,
            $skipped,
        ));

        return Command::SUCCESS;
    }

    private function hasActiveWatchdogJob(string $instanceId): bool
    {
        $activeStatuses = [JobStatus::Queued, JobStatus::Claimed, JobStatus::Running];

        $count = $this->jobRepository->createQueryBuilder('j')
            ->select('COUNT(j.id)')
            ->andWhere('j.type = :type')
            ->andWhere('j.status IN (:statuses)')
            ->andWhere('j.payload LIKE :instancePayload')
            ->setParameter('type', 'instance.watchdog.check')
            ->setParameter('statuses', $activeStatuses)
            ->setParameter('instancePayload', '%"instance_id":"' . $instanceId . '"%')
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }
}
