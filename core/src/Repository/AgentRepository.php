<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\Agent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Agent>
 */
final class AgentRepository extends ServiceEntityRepository implements AgentRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Agent::class);
    }

    /** @return Agent[] */
    #[\Override]
    public function findActive(): array
    {
        return $this->findBy(['status' => Agent::STATUS_ACTIVE], ['name' => 'ASC']);
    }

    #[\Override]
    public function countHeartbeatStates(\DateTimeImmutable $onlineSince, \DateTimeImmutable $degradedSince): array
    {
        $row = MetricSampleRow::row($this->createQueryBuilder('agent')
            ->select('COUNT(agent.id) AS total')
            ->addSelect('SUM(CASE WHEN agent.lastHeartbeatAt >= :online THEN 1 ELSE 0 END) AS online')
            ->addSelect('SUM(CASE WHEN agent.lastHeartbeatAt >= :degraded AND agent.lastHeartbeatAt < :online THEN 1 ELSE 0 END) AS degraded')
            ->setParameter('online', $onlineSince, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)
            ->setParameter('degraded', $degradedSince, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)
            ->getQuery()->getSingleResult());
        $total = MetricSampleRow::integer($row['total'] ?? 0);
        $online = MetricSampleRow::integer($row['online'] ?? 0);
        $degraded = MetricSampleRow::integer($row['degraded'] ?? 0);

        return ['total' => $total, 'online' => $online, 'degraded' => $degraded, 'offline' => $total - $online - $degraded];
    }
}
