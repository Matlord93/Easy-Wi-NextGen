<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\MediaAsset;
use App\Module\Core\Domain\Entity\Site;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MediaAsset>
 */
final class MediaAssetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MediaAsset::class);
    }

    /** @return list<MediaAsset> */
    public function findBySite(?Site $site): array
    {
        /** @var list<MediaAsset> $rows */
        $rows = $this->findBy(['site' => $site], ['createdAt' => 'DESC']);

        return $rows;
    }
}
