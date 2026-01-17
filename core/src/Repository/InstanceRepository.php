<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class InstanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Instance::class);
    }

    /**
     * @return Instance[]
     */
    public function findByCustomer(User $customer): array
    {
        return $this->findBy(['customer' => $customer], ['createdAt' => 'DESC']);
    }

    /**
     * @return Instance[]
     */
    public function findScanCandidates(Agent $node, \DateTimeImmutable $threshold, int $limit): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.node = :node')
            ->andWhere('i.diskLastScannedAt IS NULL OR i.diskLastScannedAt < :threshold')
            ->setParameter('node', $node)
            ->setParameter('threshold', $threshold)
            ->orderBy('i.diskLastScannedAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
