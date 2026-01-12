<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\Webspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class WebspaceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Webspace::class);
    }

    /**
     * @return Webspace[]
     */
    public function findByCustomer(User $customer): array
    {
        return $this->createQueryBuilder('webspace')
            ->andWhere('webspace.customer = :customer')
            ->andWhere('webspace.status != :deletedStatus')
            ->setParameter('customer', $customer)
            ->setParameter('deletedStatus', Webspace::STATUS_DELETED)
            ->orderBy('webspace.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
