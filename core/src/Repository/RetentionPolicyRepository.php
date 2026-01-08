<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RetentionPolicy;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class RetentionPolicyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RetentionPolicy::class);
    }

    public function getCurrent(): ?RetentionPolicy
    {
        return $this->createQueryBuilder('policy')
            ->orderBy('policy.updatedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
