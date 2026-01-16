<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Ts3Node;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Ts3Node>
 */
final class Ts3NodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Ts3Node::class);
    }
}
