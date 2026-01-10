<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Backup;
use App\Entity\BackupDefinition;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Backup>
 */
final class BackupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Backup::class);
    }

    /**
     * @return Backup[]
     */
    public function findByDefinition(BackupDefinition $definition): array
    {
        return $this->findBy(['definition' => $definition], ['createdAt' => 'DESC']);
    }

    /**
     * @param BackupDefinition[] $definitions
     *
     * @return Backup[]
     */
    public function findByDefinitions(array $definitions): array
    {
        if ($definitions === []) {
            return [];
        }

        return $this->createQueryBuilder('backup')
            ->andWhere('backup.definition IN (:definitions)')
            ->setParameter('definitions', $definitions)
            ->orderBy('backup.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
