<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\Site;
use App\Module\Core\Domain\Entity\TeamGroup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<TeamGroup> */
final class TeamGroupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TeamGroup::class);
    }

    /** @return list<TeamGroup> */
    public function findBySite(Site $site): array
    {
        /** @var list<TeamGroup> $rows */
        $rows = $this->findBy(['site' => $site], ['sortOrder' => 'ASC', 'name' => 'ASC']);
        return $rows;
    }

    public function findOneBySiteAndSlug(Site $site, string $slug): ?TeamGroup
    {
        return $this->findOneBy(['site' => $site, 'slug' => $slug]);
    }
}
