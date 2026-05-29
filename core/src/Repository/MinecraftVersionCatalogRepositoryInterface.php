<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\MinecraftVersionCatalog;

interface MinecraftVersionCatalogRepositoryInterface
{
    /** @return array<int, string> */
    public function findVersionsByChannel(string $channel, bool $activeOnly = true): array;

    /** @return array<string, array<int, string>> */
    public function findBuildsGroupedByVersion(string $channel, bool $activeOnly = true): array;

    /** @return array<int, MinecraftVersionCatalog> */
    public function findActiveByChannel(string $channel): array;

    public function findLatestVersion(string $channel, bool $activeOnly = true): ?string;

    public function findLatestBuild(string $channel, string $version, bool $activeOnly = true): ?string;

    public function findEntry(string $channel, string $version, ?string $build, bool $activeOnly = true): ?MinecraftVersionCatalog;

    public function versionExists(string $channel, string $version, bool $activeOnly = true): bool;

    public function buildExists(string $channel, string $version, string $build, bool $activeOnly = true): bool;
}
