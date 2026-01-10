<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Job;
use App\Repository\AgentRepository;
use App\Repository\InstanceRepository;
use App\Service\InstanceFilesystemResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'disk:scan:reconcile',
    description: 'Queue instance disk scans and node disk stat jobs.',
)]
final class DiskScanReconcileCommand extends Command
{
    private const DEFAULT_BATCH_LIMIT = 50;

    public function __construct(
        private readonly AgentRepository $agentRepository,
        private readonly InstanceRepository $instanceRepository,
        private readonly InstanceFilesystemResolver $filesystemResolver,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = new \DateTimeImmutable();
        $queuedScans = 0;
        $queuedStats = 0;

        $nodes = $this->agentRepository->findBy([], ['updatedAt' => 'DESC']);
        foreach ($nodes as $node) {
            $interval = $node->getDiskScanIntervalSeconds();
            $threshold = $now->modify(sprintf('-%d seconds', $interval));
            $remaining = self::DEFAULT_BATCH_LIMIT - $queuedScans;
            if ($remaining <= 0) {
                break;
            }

            $instances = $this->instanceRepository->findScanCandidates($node, $threshold, $remaining);
            foreach ($instances as $instance) {
                $payload = [
                    'instance_id' => (string) ($instance->getId() ?? ''),
                    'customer_id' => (string) $instance->getCustomer()->getId(),
                    'agent_id' => $node->getId(),
                    'instance_dir' => $this->filesystemResolver->resolveInstanceDir($instance),
                ];

                $job = new Job('instance.disk.scan', $payload);
                $this->entityManager->persist($job);
                $queuedScans++;

                if ($queuedScans >= self::DEFAULT_BATCH_LIMIT) {
                    break 2;
                }
            }
        }

        foreach ($nodes as $node) {
            $metadata = $node->getMetadata();
            $stat = is_array($metadata) ? ($metadata['disk_stat'] ?? null) : null;
            $checkedAt = null;
            if (is_array($stat) && is_string($stat['checked_at'] ?? null)) {
                try {
                    $checkedAt = new \DateTimeImmutable($stat['checked_at']);
                } catch (\Exception) {
                    $checkedAt = null;
                }
            }

            $interval = $node->getDiskScanIntervalSeconds();
            if ($checkedAt !== null && $checkedAt->modify(sprintf('+%d seconds', $interval)) > $now) {
                continue;
            }

            $job = new Job('node.disk.stat', [
                'agent_id' => $node->getId(),
                'node_id' => $node->getId(),
            ]);
            $this->entityManager->persist($job);
            $queuedStats++;
        }

        $this->entityManager->flush();

        $output->writeln(sprintf('Queued %d instance disk scan(s).', $queuedScans));
        $output->writeln(sprintf('Queued %d node disk stat job(s).', $queuedStats));

        return Command::SUCCESS;
    }
}
