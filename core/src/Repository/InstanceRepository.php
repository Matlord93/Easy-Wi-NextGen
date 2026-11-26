<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\InstanceStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Instance>
 */
final class InstanceRepository extends ServiceEntityRepository implements InstanceRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Instance::class);
    }

    /**
     * @return Instance[]
     */
    #[\Override]
    public function findByCustomer(User $customer): array
    {
        return $this->findBy(['customer' => $customer], ['createdAt' => 'DESC']);
    }

    /**
     * @param list<Agent> $nodes
     * @param list<int|string> $excludedIds
     * @return Instance[]
     */
    #[\Override]
    public function findScanCandidates(array $nodes, \DateTimeImmutable $now, int $limit, array $excludedIds): array
    {
        if ($nodes === []) {
            return [];
        }

        $query = $this->createQueryBuilder('i');
        $due = $query->expr()->orX();
        foreach ($nodes as $index => $node) {
            $nodeParam = 'node'.$index;
            $timeParam = 'time'.$index;
            $due->add(sprintf('(i.node = :%s AND (i.diskLastScannedAt IS NULL OR i.diskLastScannedAt <= :%s))', $nodeParam, $timeParam));
            $query->setParameter($nodeParam, QueryParameter::entityId($node->getId(), Agent::class))
                ->setParameter($timeParam, $now->modify(sprintf('-%d seconds', $node->getDiskScanIntervalSeconds())), Types::DATETIME_IMMUTABLE);
        }
        $query->andWhere($due)
            ->andWhere('i.status NOT IN (:uninstalled)')
            ->setParameter('uninstalled', [InstanceStatus::PendingSetup->value, InstanceStatus::Provisioning->value])
            ->orderBy('i.diskLastScannedAt', 'ASC')
            ->addOrderBy('i.id', 'ASC')
            ->setMaxResults($limit);
        if ([] !== $excludedIds) {
            $query->andWhere('i.id NOT IN (:activeScans)')->setParameter('activeScans', array_map('intval', $excludedIds));
        }

        return EntityQueryResult::all($query->getQuery(), Instance::class);
    }

    /**
     * @return Instance[]
     */
    #[\Override]
    public function findWatchdogEnabledBatchAfterId(int $afterId, int $limit): array
    {
        return EntityQueryResult::all($this->createQueryBuilder('i')
            ->andWhere('i.watchdogEnabled = true')
            ->andWhere('i.id > :afterId')
            ->setParameter('afterId', $afterId)
            ->orderBy('i.id', 'ASC')
            ->setMaxResults(max(1, min($limit, 1000)))
            ->getQuery(), \App\Module\Core\Domain\Entity\Instance::class);
    }
}
