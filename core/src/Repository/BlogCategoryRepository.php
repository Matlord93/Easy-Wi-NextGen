<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\BlogCategory;
use App\Module\Core\Domain\Entity\Site;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class BlogCategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BlogCategory::class);
    }

    /**
     * @return list<BlogCategory>
     */
    public function findBySite(Site $site): array
    {
        /** @var list<BlogCategory> $rows */
        $rows = $this->findBy(['site' => $site], ['name' => 'ASC']);

        return $rows;
    }

    public function findOneBySiteAndSlug(Site $site, string $slug): ?BlogCategory
    {
        /** @var BlogCategory|null $row */
        $row = $this->findOneBy(['site' => $site, 'slug' => $slug]);

        return $row;
    }
}
