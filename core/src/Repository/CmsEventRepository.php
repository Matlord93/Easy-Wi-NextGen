<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\CmsEvent;
use App\Module\Core\Domain\Entity\Site;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CmsEvent>
 */
final class CmsEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CmsEvent::class);
    }

    /** @return list<CmsEvent> */
    public function findPublishedBySite(Site $site): array
    {
        /** @var list<CmsEvent> $rows */
        $rows = $this->findBy(['site' => $site, 'isPublished' => true], ['startAt' => 'ASC']);

        return $rows;
    }

    /** @return list<CmsEvent> */
    public function findBySite(Site $site): array
    {
        /** @var list<CmsEvent> $rows */
        $rows = $this->findBy(['site' => $site], ['startAt' => 'ASC']);

        return $rows;
    }

    public function findOneBySiteAndSlug(Site $site, string $slug, bool $publishedOnly = true): ?CmsEvent
    {
        /** @var CmsEvent|null $row */
        $row = $this->findOneBy(
            $publishedOnly
            ? ['site' => $site, 'slug' => $slug, 'isPublished' => true]
            : ['site' => $site, 'slug' => $slug]
        );

        return $row;
    }
}
