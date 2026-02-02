<?php

declare(strict_types=1);

namespace App\Module\Unifi\Infrastructure\Repository;

use App\Module\Unifi\Domain\Entity\UnifiManualRule;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UnifiManualRule>
 */
class UnifiManualRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UnifiManualRule::class);
    }

    /**
     * @return UnifiManualRule[]
     */
    public function findEnabled(): array
    {
        return $this->findBy(['enabled' => true], ['createdAt' => 'DESC']);
    }
}
