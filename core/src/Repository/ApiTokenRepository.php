<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\ApiToken;
use App\Module\Core\Domain\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ApiToken>
 */
final class ApiTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiToken::class);
    }

    public function deleteByCustomer(User $customer): void
    {
        EntityQueryResult::affected($this->createQueryBuilder('token')
            ->delete()
            ->andWhere('token.customer = :customer')
            ->setParameter('customer', QueryParameter::entityId($customer->getId(), \App\Module\Core\Domain\Entity\User::class))
            ->getQuery());
    }
}
