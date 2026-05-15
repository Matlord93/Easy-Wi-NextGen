<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use Psr\Cache\CacheItemPoolInterface;

final class CoreReleaseChecker
{
    private const CACHE_KEY = 'core.latest_release_version';
    private const CHECKSUMS_ASSET = 'checksums-core.txt';
    private const SIGNATURE_ASSET = 'checksums-core.txt.asc';
    private const STATIC_CORE_ASSETS = ['easywi-core.tar.gz', 'easywi-core.zip'];

    public const CHANNEL_STABLE = GithubReleaseResolver::CHANNEL_STABLE;
    public const CHANNEL_BETA = GithubReleaseResolver::CHANNEL_BETA;
    public const CHANNEL_DEV = GithubReleaseResolver::CHANNEL_DEV;
    /** @deprecated use CHANNEL_DEV */
    public const CHANNEL_ALPHA = GithubReleaseResolver::CHANNEL_ALPHA;

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly string $repository,
        private readonly int $cacheTtlSeconds = 300,
        private readonly string $channel = self::CHANNEL_STABLE,
        private readonly ?GithubReleaseResolver $releaseResolver = null,
    ) {
    }

    public function getRepository(): string
    {
        return $this->repository;
    }

    public function getChannel(): string
    {
        return $this->resolver()->normalizeChannel($this->channel);
    }

    public function getLatestVersion(bool $force = false): ?string
    {
        return $this->getLatestVersionForChannel($this->channel, $force);
    }

    public function getLatestVersionForChannel(string $channel, bool $force = false): ?string
    {
        if (trim($this->repository) === '') {
            return null;
        }

        $channel = $this->resolver()->normalizeChannel($channel);
        $cacheKey = sprintf('%s.%s.%s', self::CACHE_KEY, sha1($this->repository), $channel);
        $item = $this->cache->getItem($cacheKey);
        $cached = $item->get();
        if (!$force && $item->isHit() && is_string($cached) && $cached !== '') {
            return $cached;
        }

        $latest = $this->resolver()->getLatestVersion($this->repository, $channel, 'core', $force);
        if ($latest !== null) {
            $item->set($latest);
            $item->expiresAfter($this->cacheTtlSeconds);
            $this->cache->save($item);
        }

        return $latest;
    }

    /** @return array{version:string,download_url:string,checksums_url:string,signature_url:?string,asset_name:string,channel:string}|null */
    public function getReleasePackageForChannel(string $channel, ?string $targetVersion = null, bool $force = false): ?array
    {
        if (trim($this->repository) === '') {
            return null;
        }

        return $this->resolver()->getLatestAssetMatching(
            $this->repository,
            $channel,
            self::coreAssetMatcher(),
            self::CHECKSUMS_ASSET,
            self::SIGNATURE_ASSET,
            $targetVersion,
            'core',
            $force,
        );
    }

    public function describeReleasePackageSelectionFailure(string $channel, ?string $targetVersion = null, bool $force = false): string
    {
        if (trim($this->repository) === '') {
            return 'Kein GitHub Repository für Core-Updates konfiguriert.';
        }

        return $this->resolver()->describeLatestAssetSelectionFailure(
            $this->repository,
            $channel,
            self::coreAssetMatcher(),
            self::CHECKSUMS_ASSET,
            $targetVersion,
            'core',
            $force,
        );
    }

    public function isUpdateAvailable(?string $currentVersion, ?string $latestVersion = null): ?bool
    {
        $current = $this->resolver()->normalizeVersion($currentVersion);
        $latest = $this->resolver()->normalizeVersion($latestVersion ?? $this->getLatestVersion());

        if ($current === null || $latest === null) {
            return null;
        }

        return version_compare($current, $latest, '<');
    }

    /** @return string[] */
    public static function allowedCoreAssetPatterns(): array
    {
        return [
            'easywi-core.tar.gz',
            'easywi-core.zip',
            'easywi-webinterface-<version>.tar.gz',
            'easywi-webinterface-<version>.zip',
            'easywi-webinterface-v<version>.tar.gz',
            'easywi-webinterface-v<version>.zip',
        ];
    }

    /** @return callable(string,string):bool|int */
    public static function coreAssetMatcher(): callable
    {
        return static function (string $assetName, string $releaseTag): bool|int {
            $assetName = trim($assetName);
            if ($assetName === '' || self::isForbiddenCoreAssetName($assetName)) {
                return false;
            }

            if ($assetName === 'easywi-core.tar.gz') {
                return 0;
            }
            if ($assetName === 'easywi-core.zip') {
                return 1;
            }

            $version = ltrim(trim($releaseTag), 'vV');
            if ($version === '') {
                return false;
            }

            return match ($assetName) {
                sprintf('easywi-webinterface-%s.tar.gz', $version) => 10,
                sprintf('easywi-webinterface-%s.zip', $version) => 11,
                sprintf('easywi-webinterface-v%s.tar.gz', $version) => 12,
                sprintf('easywi-webinterface-v%s.zip', $version) => 13,
                default => false,
            };
        };
    }

    public static function isForbiddenCoreAssetName(string $assetName): bool
    {
        $lower = strtolower(basename(trim($assetName)));
        if ($lower === '' || str_ends_with($lower, '.asc') || str_starts_with($lower, 'checksums-')) {
            return true;
        }

        return preg_match('/(?:^|[-_.])(update-agent|agent|patch|delta)(?:$|[-_.])/', $lower) === 1;
    }

    /** @return array<string, mixed> */
    public function getCacheStatus(?string $channel = null): array
    {
        return $this->resolver()->getCacheStatus($this->repository, $channel ?? $this->channel, 'core');
    }

    /** @return array<string, string> */
    public static function channels(): array
    {
        return [
            self::CHANNEL_STABLE => 'Stable',
            self::CHANNEL_BETA => 'Beta',
            self::CHANNEL_DEV => 'Dev',
        ];
    }

    private function resolver(): GithubReleaseResolver
    {
        if ($this->releaseResolver !== null) {
            return $this->releaseResolver;
        }

        throw new \LogicException('GithubReleaseResolver service is not configured.');
    }
}
