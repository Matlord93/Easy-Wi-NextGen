<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\Database;
use App\Module\Core\Domain\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class DatabaseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Database::class);
    }

    /** @return Database[] */
    public function findByCustomer(User $customer, int $limit = 100): array
    {
        return $this->createQueryBuilder('database')
            ->andWhere('database.customer = :customer')
            ->setParameter('customer', $customer)
            ->orderBy('database.updatedAt', 'DESC')
            ->setMaxResults(max(1, min(500, $limit)))
            ->getQuery()
            ->getResult();
    }

    public function findOneByCustomerAndName(User $customer, string $engine, string $name): ?Database
    {
        return $this->findOneBy(['customer' => $customer, 'engine' => strtolower($engine), 'name' => $name]);
    }

    public function findOneByCustomerAndUsername(User $customer, string $engine, string $username): ?Database
    {
        return $this->findOneBy(['customer' => $customer, 'engine' => strtolower($engine), 'username' => $username]);
    }
}
