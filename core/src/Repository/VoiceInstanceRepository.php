<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Entity\VoiceInstance;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class VoiceInstanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VoiceInstance::class);
    }

    /** @return VoiceInstance[] */
    public function findByCustomer(User $customer, int $limit = 100): array
    {
        return $this->createQueryBuilder('voice')
            ->andWhere('voice.customer = :customer')
            ->setParameter('customer', $customer)
            ->orderBy('voice.updatedAt', 'DESC')
            ->setMaxResults(max(1, min(200, $limit)))
            ->getQuery()
            ->getResult();
    }

    /**
     * @param int[] $nodeIds
     * @return array<int, VoiceInstance>
     */
    public function findLatestByNodeIds(array $nodeIds): array
    {
        if ($nodeIds === []) {
            return [];
        }

        $instances = $this->createQueryBuilder('voice')
            ->andWhere('voice.node IN (:nodeIds)')
            ->setParameter('nodeIds', $nodeIds)
            ->orderBy('voice.checkedAt', 'DESC')
            ->addOrderBy('voice.updatedAt', 'DESC')
            ->setMaxResults(max(1, min(200, count($nodeIds) * 5)))
            ->getQuery()
            ->getResult();

        $latest = [];
        foreach ($instances as $instance) {
            $nodeId = $instance->getNode()->getId();
            if (!is_int($nodeId) || isset($latest[$nodeId])) {
                continue;
            }
            $latest[$nodeId] = $instance;
        }

        return $latest;
    }

    public function findOneLatestByNodeId(int $nodeId): ?VoiceInstance
    {
        return $this->createQueryBuilder('voice')
            ->andWhere('voice.node = :nodeId')
            ->setParameter('nodeId', $nodeId)
            ->orderBy('voice.checkedAt', 'DESC')
            ->addOrderBy('voice.updatedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
