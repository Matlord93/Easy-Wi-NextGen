<?php

declare(strict_types=1);

namespace App\Module\Core\Command;

use App\Module\Core\Domain\Entity\Job;
use App\Module\Gameserver\Application\GameServerPathResolver;
use App\Repository\AgentRepositoryInterface;
use App\Repository\InstanceRepositoryInterface;
use App\Repository\JobRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Lock\LockFactory;

#[AsCommand(
    name: 'disk:scan:reconcile',
    description: 'Queue instance disk scans and node disk stat jobs.',
)]
final class DiskScanReconcileCommand extends Command
{
    private const DEFAULT_BATCH_LIMIT = 50;

    public function __construct(
        private readonly AgentRepositoryInterface $agentRepository,
        private readonly InstanceRepositoryInterface $instanceRepository,
        private readonly GameServerPathResolver $filesystemResolver,
        private readonly EntityManagerInterface $entityManager,
        private readonly JobRepositoryInterface $jobRepository,
        private readonly LockFactory $locks,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $lock = $this->locks->createLock('core.disk_scan.reconcile');
        if (!$lock->acquire()) {
            return Command::SUCCESS;
        }
        try {
            return $this->queueScans($output);
        } finally {
            $lock->release();
        }
    }

    private function queueScans(OutputInterface $output): int
    {
        $now = new \DateTimeImmutable();
        $queuedScans = 0;
        $queuedStats = 0;
        $activeScans = $this->jobRepository->findActivePayloadValues('instance.disk.scan', 'instance_id');
        $activeStats = $this->jobRepository->findActivePayloadValues('node.disk.stat', 'node_id');

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
                $instanceId = (string) $instance->getId();
                if (isset($activeScans[$instanceId])) {
                    continue;
                }
                $payload = [
                    'instance_id' => (string) ($instance->getId() ?? ''),
                    'customer_id' => (string) $instance->getCustomer()->getId(),
                    'agent_id' => $node->getId(),
                    'instance_dir' => $this->filesystemResolver->resolveRoot($instance),
                ];

                $job = new Job('instance.disk.scan', $payload);
                $this->entityManager->persist($job);
                $activeScans[$instanceId] = true;
                $queuedScans++;

                if ($queuedScans >= self::DEFAULT_BATCH_LIMIT) {
                    break 2;
                }
            }
        }

        foreach ($nodes as $node) {
            if (isset($activeStats[$node->getId()])) {
                continue;
            }
            $metadata = $node->getMetadata();
            $stat = \App\Infrastructure\Config\JsonMap::fromValue($metadata['disk_stat'] ?? null);
            $checkedAt = null;
            if (is_array($stat) && is_string($stat['checked_at'] ?? null)) {
                try {
                    $checkedAt = new \DateTimeImmutable(\App\Infrastructure\Config\ScalarValue::text($stat['checked_at']));
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
