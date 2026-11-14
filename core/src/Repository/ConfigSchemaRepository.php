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
        return EntityQueryResult::all($this->createQueryBuilder('schema')
            ->andWhere('schema.gameDefinition = :gameDefinition')
            ->setParameter('gameDefinition', QueryParameter::entityId($gameDefinition->getId(), \App\Module\Core\Domain\Entity\GameDefinition::class))
            ->orderBy('schema.name', 'ASC')
            ->getQuery(), \App\Module\Core\Domain\Entity\ConfigSchema::class);
    }

    public function findOneByGameAndKey(GameDefinition $gameDefinition, string $configKey): ?ConfigSchema
    {
        return EntityQueryResult::one($this->createQueryBuilder('schema')
            ->andWhere('schema.gameDefinition = :gameDefinition')
            ->andWhere('schema.configKey = :configKey')
            ->setParameter('gameDefinition', QueryParameter::entityId($gameDefinition->getId(), \App\Module\Core\Domain\Entity\GameDefinition::class))
            ->setParameter('configKey', $configKey)
            ->getQuery(), \App\Module\Core\Domain\Entity\ConfigSchema::class);
    }
}
