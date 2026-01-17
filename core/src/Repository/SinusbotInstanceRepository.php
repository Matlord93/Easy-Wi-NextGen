<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\SinusbotInstance;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SinusbotInstance>
 */
final class SinusbotInstanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SinusbotInstance::class);
    }
}
