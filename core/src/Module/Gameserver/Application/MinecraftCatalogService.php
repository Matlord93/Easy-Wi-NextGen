<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application;

use App\Module\Core\Domain\Entity\MinecraftVersionCatalog;
use App\Repository\MinecraftVersionCatalogRepositoryInterface;

final class MinecraftCatalogService
{
    public function __construct(
        private readonly MinecraftVersionCatalogRepositoryInterface $catalogRepository,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getUiCatalog(): array
    {
        return [
            'vanilla' => [
                'versions' => $this->catalogRepository->findVersionsByChannel('vanilla'),
            ],
            'paper' => [
                'versions' => $this->catalogRepository->findVersionsByChannel('paper'),
                'builds' => $this->catalogRepository->findBuildsGroupedByVersion('paper'),
            ],
        ];
    }

    public function resolveVersion(string $channel, ?string $version): ?string
    {
        $normalized = trim((string) ($version ?? ''));
        if ($normalized === '' || strtolower($normalized) === 'latest') {
            return $this->catalogRepository->findLatestVersion($channel);
        }

        return $normalized;
    }

    public function resolveEntry(string $channel, ?string $version, ?string $build): ?MinecraftVersionCatalog
    {
        $resolvedVersion = $this->resolveVersion($channel, $version);
        if ($resolvedVersion === null || $resolvedVersion === '') {
            return null;
        }

        $resolvedBuild = $build !== null && $build !== '' ? $build : $this->catalogRepository->findLatestBuild($channel, $resolvedVersion);

        return $this->catalogRepository->findEntry($channel, $resolvedVersion, $resolvedBuild);
    }

    public function validateSelection(string $channel, ?string $version, ?string $build): ?string
    {
        $resolvedVersion = $this->resolveVersion($channel, $version);
        if ($resolvedVersion === null || $resolvedVersion === '') {
            return 'No catalog versions are available.';
        }

        $versionInput = trim((string) ($version ?? ''));
        if ($versionInput !== '' && strtolower($versionInput) !== 'latest' && !$this->catalogRepository->versionExists($channel, $versionInput)) {
            return 'Selected version is not available in the catalog.';
        }

        $buildInput = trim((string) ($build ?? ''));
        if ($buildInput !== '') {
            if (!$this->catalogRepository->buildExists($channel, $resolvedVersion, $buildInput)) {
                return 'Selected build is not available in the catalog.';
            }
        }

        return null;
    }
}
