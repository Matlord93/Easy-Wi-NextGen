<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\MinecraftVersionCatalog;

interface MinecraftVersionCatalogRepositoryInterface
{
    /**
     * @return array<int, string>
     */
    public function findVersionsByChannel(string $channel): array;

    /**
     * @return array<string, array<int, string>>
     */
    public function findBuildsGroupedByVersion(string $channel): array;

    public function findLatestVersion(string $channel): ?string;

    public function findLatestBuild(string $channel, string $version): ?string;

    public function findEntry(string $channel, string $version, ?string $build): ?MinecraftVersionCatalog;

    public function versionExists(string $channel, string $version): bool;

    public function buildExists(string $channel, string $version, string $build): bool;
}
