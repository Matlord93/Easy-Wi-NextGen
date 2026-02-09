<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\MetricSample;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MetricSample>
 */
final class MetricSampleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MetricSample::class);
    }

    /**
     * @return MetricSample[]
     */
    public function findForAgentSince(Agent $agent, \DateTimeImmutable $since): array
    {
        return $this->createQueryBuilder('sample')
            ->andWhere('sample.agent = :agent')
            ->andWhere('sample.recordedAt >= :since')
            ->setParameter('agent', $agent)
            ->setParameter('since', $since)
            ->orderBy('sample.recordedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array<int, array{recordedAt: \DateTimeImmutable, cpuPercent: ?float, memoryPercent: ?float, diskPercent: ?float, netBytesSent: ?int, netBytesRecv: ?int, payload: ?array}>
     */
    public function findSeriesForAgentSince(Agent $agent, \DateTimeImmutable $since): array
    {
        return $this->createQueryBuilder('sample')
            ->select(
                'sample.recordedAt AS recordedAt',
                'sample.cpuPercent AS cpuPercent',
                'sample.memoryPercent AS memoryPercent',
                'sample.diskPercent AS diskPercent',
                'sample.netBytesSent AS netBytesSent',
                'sample.netBytesRecv AS netBytesRecv',
                'sample.payload AS payload',
            )
            ->andWhere('sample.agent = :agent')
            ->andWhere('sample.recordedAt >= :since')
            ->setParameter('agent', $agent)
            ->setParameter('since', $since)
            ->orderBy('sample.recordedAt', 'ASC')
            ->getQuery()
            ->getArrayResult();
    }
}
