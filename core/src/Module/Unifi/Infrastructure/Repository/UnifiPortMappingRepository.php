<?php

declare(strict_types=1);

namespace App\Module\Unifi\Infrastructure\Repository;

use App\Module\Unifi\Domain\Entity\UnifiPortMapping;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UnifiPortMapping>
 */
final class UnifiPortMappingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UnifiPortMapping::class);
    }

    public function findOneByRuleName(string $ruleName): ?UnifiPortMapping
    {
        return $this->findOneBy(['ruleName' => $ruleName]);
    }
}
