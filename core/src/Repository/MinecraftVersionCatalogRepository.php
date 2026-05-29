<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\MinecraftVersionCatalog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MinecraftVersionCatalog>
 */
final class MinecraftVersionCatalogRepository extends ServiceEntityRepository implements MinecraftVersionCatalogRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MinecraftVersionCatalog::class);
    }

    /**
     * @return array<int, string>
     */
    public function findVersionsByChannel(string $channel, bool $activeOnly = true): array
    {
        $qb = $this->createQueryBuilder('catalog')
            ->select('catalog.mcVersion AS version', 'MAX(catalog.releasedAt) AS releasedAt')
            ->where('catalog.channel = :channel')
            ->setParameter('channel', $channel);

        if ($activeOnly) {
            $qb->andWhere('catalog.isActive = true');
        }

        $rows = $qb
            ->groupBy('catalog.mcVersion')
            ->orderBy('releasedAt', 'DESC')
            ->addOrderBy('catalog.mcVersion', 'DESC')
            ->getQuery()
            ->getArrayResult();

        return array_values(array_map(static fn (array $row): string => (string) ($row['version'] ?? ''), $rows));
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function findBuildsGroupedByVersion(string $channel, bool $activeOnly = true): array
    {
        $qb = $this->createQueryBuilder('catalog')
            ->select('catalog.mcVersion AS version', 'catalog.build AS build')
            ->where('catalog.channel = :channel')
            ->andWhere('catalog.build IS NOT NULL')
            ->setParameter('channel', $channel);

        if ($activeOnly) {
            $qb->andWhere('catalog.isActive = true');
        }

        $rows = $qb
            ->orderBy('catalog.mcVersion', 'DESC')
            ->addOrderBy('catalog.releasedAt', 'DESC')
            ->addOrderBy('catalog.build', 'DESC')
            ->getQuery()
            ->getArrayResult();

        $grouped = [];
        foreach ($rows as $row) {
            $version = (string) ($row['version'] ?? '');
            $build = (string) ($row['build'] ?? '');
            if ($version === '' || $build === '') {
                continue;
            }
            $grouped[$version] ??= [];
            if (!in_array($build, $grouped[$version], true)) {
                $grouped[$version][] = $build;
            }
        }

        return $grouped;
    }


    /**
     * @return array<int, MinecraftVersionCatalog>
     */
    public function findActiveByChannel(string $channel): array
    {
        return $this->createQueryBuilder('catalog')
            ->where('catalog.channel = :channel')
            ->andWhere('catalog.isActive = true')
            ->setParameter('channel', $channel)
            ->orderBy('catalog.releasedAt', 'DESC')
            ->addOrderBy('catalog.mcVersion', 'DESC')
            ->addOrderBy('catalog.build', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findLatestVersion(string $channel, bool $activeOnly = true): ?string
    {
        $qb = $this->createQueryBuilder('catalog')
            ->where('catalog.channel = :channel')
            ->setParameter('channel', $channel);

        if ($activeOnly) {
            $qb->andWhere('catalog.isActive = true');
        }

        $entry = $qb
            ->orderBy('catalog.releasedAt', 'DESC')
            ->addOrderBy('catalog.mcVersion', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $entry?->getMcVersion();
    }

    public function findLatestBuild(string $channel, string $version, bool $activeOnly = true): ?string
    {
        $qb = $this->createQueryBuilder('catalog')
            ->where('catalog.channel = :channel')
            ->andWhere('catalog.mcVersion = :version')
            ->setParameter('channel', $channel)
            ->setParameter('version', $version);

        if ($activeOnly) {
            $qb->andWhere('catalog.isActive = true');
        }

        $entry = $qb
            ->orderBy('catalog.releasedAt', 'DESC')
            ->addOrderBy('catalog.build', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $entry?->getBuild();
    }

    public function findEntry(string $channel, string $version, ?string $build, bool $activeOnly = true): ?MinecraftVersionCatalog
    {
        $criteria = [
            'channel' => $channel,
            'mcVersion' => $version,
        ];
        if ($build !== null && $build !== '') {
            $criteria['build'] = $build;
            if ($activeOnly) {
                $criteria['isActive'] = true;
            }
            return $this->findOneBy($criteria);
        }

        $qb = $this->createQueryBuilder('catalog')
            ->where('catalog.channel = :channel')
            ->andWhere('catalog.mcVersion = :version')
            ->setParameter('channel', $channel)
            ->setParameter('version', $version);

        if ($activeOnly) {
            $qb->andWhere('catalog.isActive = true');
        }

        return $qb
            ->orderBy('catalog.releasedAt', 'DESC')
            ->addOrderBy('catalog.build', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function versionExists(string $channel, string $version, bool $activeOnly = true): bool
    {
        $qb = $this->createQueryBuilder('catalog')
            ->select('COUNT(catalog.id)')
            ->where('catalog.channel = :channel')
            ->andWhere('catalog.mcVersion = :version')
            ->setParameter('channel', $channel)
            ->setParameter('version', $version);

        if ($activeOnly) {
            $qb->andWhere('catalog.isActive = true');
        }

        return (int) $qb
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    public function buildExists(string $channel, string $version, string $build, bool $activeOnly = true): bool
    {
        $qb = $this->createQueryBuilder('catalog')
            ->select('COUNT(catalog.id)')
            ->where('catalog.channel = :channel')
            ->andWhere('catalog.mcVersion = :version')
            ->andWhere('catalog.build = :build')
            ->setParameter('channel', $channel)
            ->setParameter('version', $version)
            ->setParameter('build', $build);

        if ($activeOnly) {
            $qb->andWhere('catalog.isActive = true');
        }

        return (int) $qb
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    public function upsert(
        string $channel,
        string $mcVersion,
        ?string $build,
        string $downloadUrl,
        ?string $sha256,
        ?\DateTimeImmutable $releasedAt,
    ): void {
        $entry = $this->findOneBy([
            'channel' => $channel,
            'mcVersion' => $mcVersion,
            'build' => $build,
        ]);

        if (!$entry instanceof MinecraftVersionCatalog) {
            $entry = new MinecraftVersionCatalog($channel, $mcVersion, $build, $downloadUrl, $sha256, $releasedAt);
            $this->getEntityManager()->persist($entry);
            return;
        }

        $entry->setDownloadUrl($downloadUrl);
        $entry->setSha256($sha256);
        $entry->setDownloadUrl($downloadUrl);
        $entry->setSha256($sha256);
        $entry->setReleasedAt($releasedAt);
        $entry->setSource('import');
        $entry->setIsActive(true);
    }
}
