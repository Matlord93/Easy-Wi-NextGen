<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class UserRepository extends ServiceEntityRepository
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

    /**
     * @return array<int, array{id: int, email: string, type: string, totpEnabled: bool}>
     */
    public function findTwoFactorOverview(): array
    {
        $rows = $this->createQueryBuilder('user')
            ->select('user.id', 'user.email', 'user.type', 'user.totpEnabled')
            ->orderBy('user.email', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'email' => (string) $row['email'],
                'type' => (string) $row['type'],
                'totpEnabled' => (bool) $row['totpEnabled'],
            ],
            $rows,
        );
    }
}
