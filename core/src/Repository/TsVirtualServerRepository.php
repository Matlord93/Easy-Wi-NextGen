<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\TsVirtualServer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TsVirtualServer>
 */
final class TsVirtualServerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TsVirtualServer::class);
    }
}
