<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\ForumPostReport;
use App\Module\Core\Domain\Entity\Site;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ForumPostReport>
 */
final class ForumPostReportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForumPostReport::class);
    }

    /** @return list<ForumPostReport> */
    public function findForSite(Site $site, string $status): array
    {
        return EntityQueryResult::all($this->createQueryBuilder('report')
            ->join('report.post', 'post')->addSelect('post')
            ->andWhere('post.site = :site AND report.status = :status')
            ->setParameter('site', QueryParameter::entityId($site->getId(), Site::class))
            ->setParameter('status', $status)->orderBy('report.createdAt', 'DESC')
            ->getQuery(), ForumPostReport::class);
    }
}
