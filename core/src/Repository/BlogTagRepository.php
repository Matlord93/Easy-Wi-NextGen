<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\BlogTag;
use App\Module\Core\Domain\Entity\Site;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class BlogTagRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BlogTag::class);
    }

    /**
     * @return list<BlogTag>
     */
    public function findBySite(Site $site): array
    {
        /** @var list<BlogTag> $rows */
        $rows = $this->findBy(['site' => $site], ['name' => 'ASC']);

        return $rows;
    }

    public function findOneBySiteAndSlug(Site $site, string $slug): ?BlogTag
    {
        /** @var BlogTag|null $row */
        $row = $this->findOneBy(['site' => $site, 'slug' => $slug]);

        return $row;
    }
}
