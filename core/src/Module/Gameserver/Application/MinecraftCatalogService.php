<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application;

use App\Module\Core\Domain\Entity\MinecraftVersionCatalog;
use App\Repository\MinecraftVersionCatalogRepositoryInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class MinecraftCatalogService
{
    public function __construct(
        private readonly MinecraftVersionCatalogRepositoryInterface $catalogRepository,
        private readonly ?TranslatorInterface $translator = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function getUiCatalog(): array
    {
        return [
            'vanilla' => ['versions' => $this->sortVersions($this->catalogRepository->findVersionsByChannel('vanilla', true))],
            'paper' => [
                'versions' => $this->sortVersions($this->catalogRepository->findVersionsByChannel('paper', true)),
                'builds' => $this->sortBuilds($this->catalogRepository->findBuildsGroupedByVersion('paper', true)),
            ],
            'bedrock' => ['versions' => $this->sortVersions($this->catalogRepository->findVersionsByChannel('bedrock', true))],
        ];
    }

    public function resolveVersion(string $channel, ?string $version): ?string
    {
        $normalized = trim((string) ($version ?? ''));
        if ($normalized === '' || strtolower($normalized) === 'latest') {
            return $this->catalogRepository->findLatestVersion($channel, true);
        }

        return $normalized;
    }

    public function resolveEntry(string $channel, ?string $version, ?string $build): ?MinecraftVersionCatalog
    {
        $resolvedVersion = $this->resolveVersion($channel, $version);
        if ($resolvedVersion === null || $resolvedVersion === '') {
            return null;
        }

        $resolvedBuild = null;
        if ($channel === 'paper') {
            $resolvedBuild = $build !== null && trim($build) !== '' ? trim($build) : $this->catalogRepository->findLatestBuild($channel, $resolvedVersion, true);
        }

        return $this->catalogRepository->findEntry($channel, $resolvedVersion, $resolvedBuild, true);
    }

    public function validateSelection(string $channel, ?string $version, ?string $build): ?string
    {
        if (!in_array($channel, MinecraftVersionCatalog::CHANNELS, true)) {
            return $this->trans('minecraft_catalog_error_invalid_channel');
        }

        $resolvedVersion = $this->resolveVersion($channel, $version);
        if ($resolvedVersion === null || $resolvedVersion === '') {
            return $this->trans('minecraft_catalog_error_no_versions');
        }

        $versionInput = trim((string) ($version ?? ''));
        if ($versionInput !== '' && strtolower($versionInput) !== 'latest' && !$this->catalogRepository->versionExists($channel, $versionInput, true)) {
            return $this->trans('minecraft_catalog_error_version_unavailable');
        }

        $buildInput = trim((string) ($build ?? ''));
        if ($channel === 'paper' && $buildInput !== '' && !$this->catalogRepository->buildExists($channel, $resolvedVersion, $buildInput, true)) {
            return $this->trans('minecraft_catalog_error_build_unavailable');
        }
        if ($channel !== 'paper' && $buildInput !== '') {
            return $this->trans('minecraft_catalog_error_build_paper_only');
        }

        return null;
    }

    public function findNewerAvailable(\App\Module\Core\Domain\Entity\Instance $instance): ?MinecraftVersionCatalog
    {
        $channel = $this->channelFromResolver($instance);
        if ($channel === null || $instance->getInstalledVersion() === null) {
            return null;
        }
        $latest = $this->resolveEntry($channel, null, null);
        if (!$latest instanceof MinecraftVersionCatalog) {
            return null;
        }
        if ($this->compareVersions($latest->getMcVersion(), $instance->getInstalledVersion()) <= 0) {
            return null;
        }
        return $latest;
    }

    public function channelFromResolver(\App\Module\Core\Domain\Entity\Instance $instance): ?string
    {
        $resolver = $instance->getTemplate()->getInstallResolver();
        $type = is_array($resolver) ? (string) ($resolver['type'] ?? '') : '';
        return match ($type) {
            'minecraft_vanilla' => 'vanilla',
            'papermc_paper' => 'paper',
            'minecraft_bedrock' => 'bedrock',
            default => null,
        };
    }


    private function trans(string $key): string
    {
        return $this->translator?->trans($key, [], 'portal') ?? $key;
    }

    /** @param array<int, string> $versions @return array<int, string> */
    private function sortVersions(array $versions): array
    {
        usort($versions, fn (string $a, string $b): int => $this->compareVersions($b, $a));
        return $versions;
    }

    /** @param array<string, array<int, string>> $builds @return array<string, array<int, string>> */
    private function sortBuilds(array $builds): array
    {
        uksort($builds, fn (string $a, string $b): int => $this->compareVersions($b, $a));
        foreach ($builds as &$entries) {
            usort($entries, static fn (string $a, string $b): int => ((int) $b <=> (int) $a) ?: strcmp($b, $a));
        }
        unset($entries);
        return $builds;
    }

    private function compareVersions(string $left, string $right): int
    {
        $leftNormalized = preg_replace('/[^0-9.].*$/', '', $left) ?: $left;
        $rightNormalized = preg_replace('/[^0-9.].*$/', '', $right) ?: $right;
        $result = version_compare($leftNormalized, $rightNormalized);
        return $result !== 0 ? $result : strnatcmp($left, $right);
    }
}
