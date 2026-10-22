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
    public function getUiCatalog(?string $onlyChannel = null): array
    {
        $catalog = [];
        foreach ($onlyChannel === null ? MinecraftVersionCatalog::CHANNELS : [$onlyChannel] as $channel) {
            $active = $this->catalogRepository->findActiveByChannel($channel);
            $catalog[$channel] = [
                'versions' => $this->sortVersions($this->catalogRepository->findVersionsByChannel($channel, true)),
                'builds' => $this->sortBuilds($this->catalogRepository->findBuildsGroupedByVersion($channel, true)),
                'build_release_types' => array_reduce($active, static function (array $types, MinecraftVersionCatalog $entry): array { $types[$entry->getMcVersion()][$entry->getBuild() ?? ''] = $entry->getMetadata()['release_type'] ?? 'release'; return $types; }, []),
                'release_types' => array_reduce($active, static function (array $types, MinecraftVersionCatalog $entry): array { $version = $entry->getMcVersion(); $kind = $entry->getMetadata()['release_type'] ?? 'release'; if (($types[$version] ?? null) !== 'release') { $types[$version] = $kind; } return $types; }, []),
            ];
        }
        return $catalog;
    }

    public function resolveInstalledEntry(string $channel, ?string $version, ?string $build): ?MinecraftVersionCatalog
    {
        if ($version === null) { return $this->resolveEntry($channel, null, null); }
        return $this->catalogRepository->findEntry($channel, $version, $build, false);
    }

    public function resolveVersion(string $channel, ?string $version): ?string
    {
        $normalized = trim((string) ($version ?? ''));
        if ($normalized === '' || strtolower($normalized) === 'latest') {
            $entries = $this->catalogRepository->findActiveByChannel($channel);
            usort($entries, fn (MinecraftVersionCatalog $a, MinecraftVersionCatalog $b): int => $this->compareVersions($b->getMcVersion(), $a->getMcVersion()));
            foreach ($entries as $entry) {
                $kind = $entry->getMetadata()['release_type'] ?? 'release';
                if ($kind === 'release') { return $entry->getMcVersion(); }
            }
            return $entries === [] ? $this->catalogRepository->findLatestVersion($channel, true) : null;
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
        if (in_array($channel, ['paper', 'bungeecord'], true)) {
            $resolvedBuild = $build !== null && trim($build) !== '' ? trim($build) : null;
            if ($resolvedBuild === null) {
                $candidates = array_filter($this->catalogRepository->findActiveByChannel($channel), static fn (MinecraftVersionCatalog $entry): bool => $entry->getMcVersion() === $resolvedVersion && ($entry->getMetadata()['release_type'] ?? 'release') === 'release');
                usort($candidates, static fn (MinecraftVersionCatalog $a, MinecraftVersionCatalog $b): int => strnatcmp((string) $b->getBuild(), (string) $a->getBuild()));
                $resolvedBuild = $candidates === [] ? $this->catalogRepository->findLatestBuild($channel, $resolvedVersion, true) : reset($candidates)->getBuild();
            }
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
        if (in_array($channel, ['paper', 'bungeecord'], true) && $buildInput !== '' && !$this->catalogRepository->buildExists($channel, $resolvedVersion, $buildInput, true)) {
            return $this->trans('minecraft_catalog_error_build_unavailable');
        }
        if (!in_array($channel, ['paper', 'bungeecord'], true) && $buildInput !== '') {
            return $this->trans('minecraft_catalog_error_build_paper_only');
        }

        return null;
    }

    public function pinSelection(\App\Module\Core\Domain\Entity\Instance $instance, ?string $version, ?string $build): void
    {
        $channel = $this->channelFromResolver($instance);
        if ($channel === null) { return; }
        $error = $this->validateSelection($channel, $version, $build);
        if ($error !== null) { throw new \InvalidArgumentException($error); }
        $entry = $this->resolveEntry($channel, $version, $build);
        if ($entry === null) { throw new \InvalidArgumentException('No installable catalog entry.'); }
        $instance->setLockedVersion($entry->getMcVersion());
        $instance->setLockedBuildId($entry->getBuild());
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
        if (($comparison = $this->compareVersions($latest->getMcVersion(), $instance->getInstalledVersion())) < 0) { return null; }
        if ($comparison === 0 && strnatcmp((string) $latest->getBuild(), (string) $instance->getInstalledBuildId()) <= 0) { return null; }
        return $latest;
    }

    public static function channelFromResolver(\App\Module\Core\Domain\Entity\Instance $instance): ?string
    {
        $resolver = $instance->getTemplate()->getInstallResolver();
        $type = is_array($resolver) ? (string) ($resolver['type'] ?? '') : '';
        return match ($type) {
            'minecraft_vanilla' => 'vanilla',
            'papermc_paper' => 'paper',
            'minecraft_spigot' => 'spigot',
            'minecraft_bungeecord' => 'bungeecord',
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
