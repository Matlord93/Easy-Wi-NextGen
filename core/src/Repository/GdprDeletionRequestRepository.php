<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\GdprDeletionRequest;
use App\Module\Core\Domain\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GdprDeletionRequest>
 */
final class GdprDeletionRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GdprDeletionRequest::class);
    }

    public function findLatestByCustomer(User $customer): ?GdprDeletionRequest
    {
        return EntityQueryResult::one($this->createQueryBuilder('request')
            ->andWhere('request.customer = :customer')
            ->setParameter('customer', QueryParameter::entityId($customer->getId(), \App\Module\Core\Domain\Entity\User::class))
            ->orderBy('request.requestedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery(), \App\Module\Core\Domain\Entity\GdprDeletionRequest::class);
    }

    public function findByJobId(string $jobId): ?GdprDeletionRequest
    {
        return $this->findOneBy(['jobId' => $jobId]);
    }
}
