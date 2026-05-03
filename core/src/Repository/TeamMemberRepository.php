<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\Site;
use App\Module\Core\Domain\Entity\TeamMember;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TeamMember>
 */
final class TeamMemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TeamMember::class);
    }

    /** @return list<TeamMember> */
    public function findActiveBySite(Site $site): array
    {
        /** @var list<TeamMember> $rows */
        $rows = $this->findBy(['site' => $site, 'isActive' => true], ['teamName' => 'ASC', 'sortOrder' => 'ASC', 'name' => 'ASC']);

        return $rows;
    }

    /** @return list<TeamMember> */
    public function findBySite(Site $site): array
    {
        /** @var list<TeamMember> $rows */
        $rows = $this->findBy(['site' => $site], ['teamName' => 'ASC', 'sortOrder' => 'ASC', 'name' => 'ASC']);

        return $rows;
    }

    /** @return list<string> */
    public function findTeamNamesBySite(Site $site): array
    {
        $rows = $this->createQueryBuilder('m')
            ->select('DISTINCT m.teamName AS teamName')
            ->andWhere('m.site = :site')
            ->andWhere('m.teamName IS NOT NULL')
            ->setParameter('site', $site)
            ->orderBy('m.teamName', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_values(array_filter(array_map(static fn (array $row): string => (string) ($row['teamName'] ?? ''), $rows), static fn (string $name): bool => $name !== ''));
    }
}
