<?php

declare(strict_types=1);

namespace App\Repository;

use App\Infrastructure\Config\ScalarValue;
use App\Module\Core\Domain\Entity\CmsPage;
use App\Module\Core\Domain\Entity\Site;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CmsPage>
 */
final class CmsPageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CmsPage::class);
    }

    /** @return array<string,bool> */
    public function findPublicationStates(Site $site): array
    {
        $rows = ScalarQueryResult::rows($this->createQueryBuilder('page')
            ->select('page.slug', 'page.isPublished')
            ->andWhere('page.site = :site')
            ->setParameter('site', QueryParameter::entityId($site->getId(), Site::class))
            ->getQuery()->getArrayResult());
        $states = [];
        foreach ($rows as $row) {
            $states[ScalarValue::text($row['slug'] ?? null)] = filter_var($row['isPublished'] ?? false, FILTER_VALIDATE_BOOL);
        }

        return $states;
    }
}
