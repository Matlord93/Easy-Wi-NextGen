<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ModuleSetting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ModuleSetting>
 */
final class ModuleSettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ModuleSetting::class);
    }
}
