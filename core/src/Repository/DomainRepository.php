<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\Domain;
use App\Module\Core\Domain\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class DomainRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Domain::class);
    }

    /**
     * @return Domain[]
     */
    public function findByCustomer(User $customer, int $limit = 200): array
    {
        return $this->createQueryBuilder('domain')
            ->andWhere('domain.customer = :customer')
            ->setParameter('customer', $customer)
            ->orderBy('domain.createdAt', 'DESC')
            ->setMaxResults(max(1, min(500, $limit)))
            ->getQuery()
            ->getResult();
    }
}
