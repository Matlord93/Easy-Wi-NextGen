<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\MailAlias;
use App\Module\Core\Domain\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * @extends ServiceEntityRepository<MailAlias>
 */
#[AsAlias(id: MailAliasRepositoryInterface::class)]
final class MailAliasRepository extends ServiceEntityRepository implements MailAliasRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MailAlias::class);
    }

    /**
     * @return MailAlias[]
     */
    #[\Override]
    public function findByCustomer(User $customer): array
    {
        return $this->findBy(['customer' => $customer], ['updatedAt' => 'DESC']);
    }

    #[\Override]
    public function findOneByAddress(string $address): ?MailAlias
    {
        return $this->findOneBy(['address' => $address]);
    }
}
