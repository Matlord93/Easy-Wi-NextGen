<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GdprDeletionRequest;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class GdprDeletionRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GdprDeletionRequest::class);
    }

    public function findLatestByCustomer(User $customer): ?GdprDeletionRequest
    {
        return $this->createQueryBuilder('request')
            ->andWhere('request.customer = :customer')
            ->setParameter('customer', $customer)
            ->orderBy('request.requestedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByJobId(string $jobId): ?GdprDeletionRequest
    {
        return $this->findOneBy(['jobId' => $jobId]);
    }
}
