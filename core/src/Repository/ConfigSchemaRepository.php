<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\ConfigSchema;
use App\Module\Core\Domain\Entity\GameDefinition;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ConfigSchema>
 */
final class ConfigSchemaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConfigSchema::class);
    }

    /**
     * @return ConfigSchema[]
     */
    public function findByGameDefinition(GameDefinition $gameDefinition): array
    {
        return $this->createQueryBuilder('schema')
            ->andWhere('schema.gameDefinition = :gameDefinition')
            ->setParameter('gameDefinition', $gameDefinition)
            ->orderBy('schema.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByGameAndKey(GameDefinition $gameDefinition, string $configKey): ?ConfigSchema
    {
        return $this->createQueryBuilder('schema')
            ->andWhere('schema.gameDefinition = :gameDefinition')
            ->andWhere('schema.configKey = :configKey')
            ->setParameter('gameDefinition', $gameDefinition)
            ->setParameter('configKey', $configKey)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
