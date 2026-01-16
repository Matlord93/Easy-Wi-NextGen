<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Ts6VirtualServer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Ts6VirtualServer>
 */
final class Ts6VirtualServerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Ts6VirtualServer::class);
    }
}
