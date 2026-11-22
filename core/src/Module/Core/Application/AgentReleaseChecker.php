<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Update\ReleaseVersion;
use Psr\Cache\CacheItemPoolInterface;

final class AgentReleaseChecker implements AgentReleaseCheckerInterface
{
    private const CACHE_KEY = 'agent.latest_release_version';
    private const CHECKSUMS_ASSET = 'checksums-agent.txt';
    /** @var array<string, string> */
    private const PLATFORM_CHECKSUM_ASSETS = [
        'linux' => 'checksums-agent-linux.txt',
        'windows' => 'checksums-agent-windows.txt',
    ];
    private const LEGACY_CHECKSUMS_ASSET = 'checksums.sha256';

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
        return $this->getLatestVersionForChannel($this->getChannel(), $force);
    }

    #[\Override]
    public function getLatestVersionForChannel(string $channel, bool $force = false): ?string
    {
        if (trim($this->getRepository()) === '') {
            return null;
        }

        $channel = $this->resolver()->normalizeChannel($channel);
        $cacheKey = sprintf('%s.%s.%s', self::CACHE_KEY, hash('sha256', $this->repository), $channel);
        $item = $this->cache->getItem($cacheKey);
        $cached = self::cacheText($item->get());
        if (!$force && $item->isHit() && is_string($cached) && $cached !== '') {
            $cachedChannel = ReleaseVersion::prereleaseChannel($cached);
            if ($cachedChannel === null || $cachedChannel === $channel) {
                return $cached;
            }
        }

        $latest = $this->resolver()->getLatestVersion($this->repository, $channel, 'agent', $force);
        if ($latest !== null) {
            $item->set($latest);
            $item->expiresAfter($this->cacheTtlSeconds);
            $this->cache->save($item);
        }

        return $latest;
    }

    /**
     * @return array{version:string,download_url:string,checksums_url:string,signature_url:?string,asset_name:string,channel:string}|null
     */
    #[\Override]
    public function getReleaseAssetUrlsForChannel(string $assetName, string $channel, ?string $targetVersion = null, bool $force = false): ?array
    {
        if (trim($this->getRepository()) === '') {
            return null;
        }

        $checksumsAssets = $this->resolveChecksumAssetCandidates($assetName);
        // Prefer a signed checksum list for the same release when one is available.
        $unsignedRelease = null;
        foreach ($checksumsAssets as $checksumsAsset) {
            $release = $this->resolver()->getLatestAsset(
                $this->repository,
                $channel,
                $assetName,
                $checksumsAsset,
                $checksumsAsset . '.asc',
                $unsignedRelease['version'] ?? $targetVersion,
                'agent',
                $force,
            );
            if ($release === null) {
                continue;
            }
            if ($release['signature_url'] !== null) {
                return $release;
            }
            $unsignedRelease ??= $release;
        }

        return $unsignedRelease;
    }

    #[\Override]
    public function isUpdateAvailable(?string $currentVersion, ?string $latestVersion = null): ?bool
    {
        $comparison = ReleaseVersion::compare($currentVersion, $latestVersion ?? $this->getLatestVersion());

        return $comparison === null ? null : $comparison < 0;
    }

    /** @return array<string, mixed> */
    public function getCacheStatus(?string $channel = null): array
    {
        return $this->resolver()->getCacheStatus($this->repository, $channel ?? $this->channel, 'agent');
    }

    /** @param array<string, mixed> $releaseInfo */
    #[\Override]
    public function releaseAssetRequiresPanelProxy(array $releaseInfo): bool
    {
        foreach (['download_url', 'checksums_url', 'signature_url'] as $key) {
            $url = self::cacheText($releaseInfo[$key] ?? null);
            if (is_string($url) && $url !== '' && self::isAuthenticatedGithubApiAssetUrl($url)) {
                return true;
            }
        }

        return false;
    }

    public static function isAuthenticatedGithubApiAssetUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);

        return $host === 'api.github.com' && preg_match('#^/repos/[^/]+/[^/]+/releases/assets/#', $path) === 1;
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

    /** @return list<string> */
    private function resolveChecksumAssetCandidates(string $assetName): array
    {
        $lower = strtolower($assetName);
        foreach (self::PLATFORM_CHECKSUM_ASSETS as $platform => $checksumAsset) {
            if (str_contains($lower, '-' . $platform . '-')) {
                return [$checksumAsset, self::CHECKSUMS_ASSET, self::LEGACY_CHECKSUMS_ASSET];
            }
        }

        return [self::CHECKSUMS_ASSET, self::LEGACY_CHECKSUMS_ASSET];
    }

    private function resolver(): GithubReleaseResolver
    {
        if ($this->releaseResolver !== null) {
            return $this->releaseResolver;
        }

        throw new \LogicException('GithubReleaseResolver service is not configured.');
    }

    private static function cacheText(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
