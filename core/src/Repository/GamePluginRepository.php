<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\GamePlugin;
use App\Module\Core\Domain\Entity\Template;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GamePlugin>
 */
final class GamePluginRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GamePlugin::class);
    }

    /**
     * @return list<GamePlugin>
     */
    public function findByTemplateGameKey(Template $template): array
    {
        $gameKey = trim($template->getGameKey());
        if ($gameKey === '') {
            return [];
        }

        return $this->createQueryBuilder('plugin')
            ->innerJoin('plugin.template', 'template')
            ->andWhere('template.gameKey = :gameKey')
            ->setParameter('gameKey', $gameKey)
            ->orderBy('plugin.name', 'ASC')
            ->addOrderBy('plugin.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findDuplicateForGameKey(string $gameKey, string $name, string $version, ?int $excludeId = null): ?GamePlugin
    {
        $qb = $this->createQueryBuilder('plugin')
            ->innerJoin('plugin.template', 'template')
            ->andWhere('template.gameKey = :gameKey')
            ->andWhere('LOWER(plugin.name) = :name')
            ->andWhere('plugin.version = :version')
            ->setParameter('gameKey', trim($gameKey))
            ->setParameter('name', mb_strtolower(trim($name)))
            ->setParameter('version', trim($version))
            ->setMaxResults(1);

        if ($excludeId !== null) {
            $qb->andWhere('plugin.id != :excludeId')->setParameter('excludeId', $excludeId);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }
}
