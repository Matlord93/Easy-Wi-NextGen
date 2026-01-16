<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Ts6Viewer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Ts6Viewer>
 */
final class Ts6ViewerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Ts6Viewer::class);
    }
}
