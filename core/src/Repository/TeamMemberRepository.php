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
        $rows = $this->findBy(['site' => $site, 'isActive' => true], ['sortOrder' => 'ASC', 'name' => 'ASC']);

        return $rows;
    }

    /** @return list<TeamMember> */
    public function findBySite(Site $site): array
    {
        /** @var list<TeamMember> $rows */
        $rows = $this->findBy(['site' => $site], ['sortOrder' => 'ASC', 'name' => 'ASC']);

        return $rows;
    }
}
