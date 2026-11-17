<?php

declare(strict_types=1);

namespace App\Module\Ports\Infrastructure\Repository;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Ports\Domain\Entity\PortPool;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PortPool>
 */
class PortPoolRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PortPool::class);
    }

    /**
     * @return PortPool[]
     */
    public function findEnabledByNode(Agent $node): array
    {
        return $this->findBy(['node' => $node, 'enabled' => true], ['tag' => 'ASC']);
    }
}
