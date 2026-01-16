<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MinecraftVersionCatalog;
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
    public function findVersionsByChannel(string $channel): array
    {
        $rows = $this->createQueryBuilder('catalog')
            ->select('catalog.mcVersion AS version', 'MAX(catalog.releasedAt) AS releasedAt')
            ->where('catalog.channel = :channel')
            ->setParameter('channel', $channel)
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
    public function findBuildsGroupedByVersion(string $channel): array
    {
        $rows = $this->createQueryBuilder('catalog')
            ->select('catalog.mcVersion AS version', 'catalog.build AS build')
            ->where('catalog.channel = :channel')
            ->andWhere('catalog.build IS NOT NULL')
            ->setParameter('channel', $channel)
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

    public function findLatestVersion(string $channel): ?string
    {
        $entry = $this->createQueryBuilder('catalog')
            ->where('catalog.channel = :channel')
            ->setParameter('channel', $channel)
            ->orderBy('catalog.releasedAt', 'DESC')
            ->addOrderBy('catalog.mcVersion', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $entry?->getMcVersion();
    }

    public function findLatestBuild(string $channel, string $version): ?string
    {
        $entry = $this->createQueryBuilder('catalog')
            ->where('catalog.channel = :channel')
            ->andWhere('catalog.mcVersion = :version')
            ->setParameter('channel', $channel)
            ->setParameter('version', $version)
            ->orderBy('catalog.releasedAt', 'DESC')
            ->addOrderBy('catalog.build', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $entry?->getBuild();
    }

    public function findEntry(string $channel, string $version, ?string $build): ?MinecraftVersionCatalog
    {
        $criteria = [
            'channel' => $channel,
            'mcVersion' => $version,
        ];
        if ($build !== null && $build !== '') {
            $criteria['build'] = $build;
            return $this->findOneBy($criteria);
        }

        return $this->createQueryBuilder('catalog')
            ->where('catalog.channel = :channel')
            ->andWhere('catalog.mcVersion = :version')
            ->setParameter('channel', $channel)
            ->setParameter('version', $version)
            ->orderBy('catalog.releasedAt', 'DESC')
            ->addOrderBy('catalog.build', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function versionExists(string $channel, string $version): bool
    {
        return (int) $this->createQueryBuilder('catalog')
            ->select('COUNT(catalog.id)')
            ->where('catalog.channel = :channel')
            ->andWhere('catalog.mcVersion = :version')
            ->setParameter('channel', $channel)
            ->setParameter('version', $version)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    public function buildExists(string $channel, string $version, string $build): bool
    {
        return (int) $this->createQueryBuilder('catalog')
            ->select('COUNT(catalog.id)')
            ->where('catalog.channel = :channel')
            ->andWhere('catalog.mcVersion = :version')
            ->andWhere('catalog.build = :build')
            ->setParameter('channel', $channel)
            ->setParameter('version', $version)
            ->setParameter('build', $build)
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
            $this->_em->persist($entry);
            return;
        }

        $entry->setDownloadUrl($downloadUrl);
        $entry->setSha256($sha256);
        $entry->setReleasedAt($releasedAt);
    }
}
