<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SinusbotNode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SinusbotNode>
 */
final class SinusbotNodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SinusbotNode::class);
    }
}
