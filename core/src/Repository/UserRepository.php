<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
final class UserRepository extends ServiceEntityRepository implements UserRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    #[\Override]
    public function findOneByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => strtolower($email)]);
    }

    /**
     * @return User[]
     */
    #[\Override]
    public function findCustomers(): array
    {
        return $this->findBy(['type' => UserType::Customer->value], ['email' => 'ASC']);
    }

    /**
     * @return User[]
     */
    #[\Override]
    public function findCustomersForReseller(User $reseller): array
    {
        return EntityQueryResult::all($this->createQueryBuilder('user')
            ->andWhere('user.type = :type')
            ->andWhere('user.resellerOwner = :reseller')
            ->setParameter('type', UserType::Customer->value)
            ->setParameter('reseller', QueryParameter::entityId($reseller->getId(), \App\Module\Core\Domain\Entity\User::class))
            ->orderBy('user.email', 'ASC')
            ->getQuery(), \App\Module\Core\Domain\Entity\User::class);
    }

    /**
     * @return array<int, array{id: int, email: string, type: string, totpEnabled: bool}>
     */
    #[\Override]
    public function findTwoFactorOverview(): array
    {
        $rows = ScalarQueryResult::rows($this->createQueryBuilder('user')
            ->select('user.id', 'user.email', 'user.type', 'user.totpEnabled')
            ->orderBy('user.email', 'ASC')
            ->getQuery()
            ->getArrayResult());

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
