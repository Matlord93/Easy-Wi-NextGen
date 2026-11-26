<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Infrastructure\Config\JsonMap;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Gameserver\Application\GameServerPathResolver;
use App\Repository\AgentRepositoryInterface;
use App\Repository\InstanceRepositoryInterface;
use App\Repository\JobRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;

final readonly class DiskScanScheduler
{
    private const DEFAULT_BATCH_LIMIT = 50;

    public function __construct(
        private AgentRepositoryInterface $agentRepository,
        private InstanceRepositoryInterface $instanceRepository,
        private GameServerPathResolver $filesystemResolver,
        private EntityManagerInterface $entityManager,
        private JobRepositoryInterface $jobRepository,
        private LockFactory $locks,
    ) {
    }

    /** @return array{scans: int, stats: int} */
    public function run(\DateTimeImmutable $now): array
    {
        $lock = $this->locks->createLock('core.disk_scan.reconcile');
        if (!$lock->acquire()) {
            return ['scans' => 0, 'stats' => 0];
        }
        try {
            return $this->queueScans($now);
        } finally {
            $lock->release();
        }
    }

    /** @return array{scans: int, stats: int} */
    private function queueScans(\DateTimeImmutable $now): array
    {
        $queuedScans = 0;
        $queuedStats = 0;
        $activeScans = $this->jobRepository->findActivePayloadValues('instance.disk.scan', 'instance_id');
        $activeStats = $this->jobRepository->findActivePayloadValues('node.disk.stat', 'node_id');

        $nodes = array_filter($this->agentRepository->findActive(), static function (Agent $node) use ($now): bool {
            $stats = JsonMap::strings($node->getLastHeartbeatStats());
            $metadata = JsonMap::strings($node->getMetadata());

            return $node->resolveLifecycleStatus($now) === Agent::STATUS_ACTIVE
                && strtolower($stats['os'] ?? $metadata['os'] ?? $metadata['os_type'] ?? 'linux') !== 'windows';
        });
        $instances = $this->instanceRepository->findScanCandidates(array_values($nodes), $now, self::DEFAULT_BATCH_LIMIT, array_keys($activeScans));
        foreach ($instances as $instance) {
            try {
                $path = $this->filesystemResolver->resolveRoot($instance);
            } catch (\RuntimeException $error) {
                $instance->setDiskLastScannedAt($now);
                $instance->setDiskScanError($error->getMessage());
                continue;
            }
            $payload = [
                'instance_id' => (string) $instance->getId(),
                'customer_id' => (string) $instance->getCustomer()->getId(),
                'agent_id' => $instance->getNode()->getId(),
                'instance_dir' => $path,
                'base_dir' => $instance->getInstanceBaseDir() ?? dirname($path),
            ];
            $this->entityManager->persist(new Job('instance.disk.scan', $payload));
            $queuedScans++;
        }

        foreach ($nodes as $node) {
            if (isset($activeStats[$node->getId()])) {
                continue;
            }
            $metadata = $node->getMetadata();
            $stat = JsonMap::strings($metadata['disk_stat'] ?? null);
            $checkedAt = null;
            if (null !== ($lastAttempt = $stat['last_attempt_at'] ?? $stat['checked_at'] ?? null)) {
                try {
                    $checkedAt = new \DateTimeImmutable($lastAttempt);
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

        return ['scans' => $queuedScans, 'stats' => $queuedStats];
    }
}
