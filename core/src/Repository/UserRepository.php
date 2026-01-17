<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findOneByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => strtolower($email)]);
    }

    /**
     * @return User[]
     */
    public function findCustomers(): array
    {
        return $this->findBy(['type' => UserType::Customer->value], ['email' => 'ASC']);
    }

    /**
     * @return User[]
     */
    public function findCustomersForReseller(User $reseller): array
    {
        return $this->createQueryBuilder('user')
            ->andWhere('user.type = :type')
            ->andWhere('user.resellerOwner = :reseller')
            ->setParameter('type', UserType::Customer->value)
            ->setParameter('reseller', $reseller)
            ->orderBy('user.email', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
