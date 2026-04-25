<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use Psr\Cache\CacheItemPoolInterface;

final class AgentReleaseChecker
{
    private const CACHE_KEY = 'agent.latest_release_version';

    public const CHANNEL_STABLE = 'stable';
    public const CHANNEL_BETA = 'beta';
    public const CHANNEL_ALPHA = 'alpha';

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly string $repository,
        private readonly int $cacheTtlSeconds = 300,
        private readonly string $channel = self::CHANNEL_STABLE,
    ) {
    }

    public function getRepository(): string
    {
        return $this->repository;
    }

    public function getChannel(): string
    {
        return $this->normalizeChannel($this->channel);
    }

    public function getLatestVersion(): ?string
    {
        return $this->getLatestVersionForChannel($this->channel);
    }

    public function getLatestVersionForChannel(string $channel): ?string
    {
        if ($this->repository === '') {
            return null;
        }

        $channel = $this->normalizeChannel($channel);
        $cacheKey = sprintf('%s.%s', self::CACHE_KEY, $channel);

        $item = $this->cache->getItem($cacheKey);
        $cached = $item->get();
        if ($item->isHit() && is_string($cached) && $cached !== '') {
            return $cached;
        }

        $latest = $this->fetchLatestVersion($channel);
        if ($latest !== null) {
            $item->set($latest);
            $item->expiresAfter($this->cacheTtlSeconds);
            $this->cache->save($item);
        }

        return $latest;
    }

    public function getReleaseAssetUrls(string $assetName, ?string $targetVersion = null): ?array
    {
        return $this->getReleaseAssetUrlsForChannel($assetName, $this->channel, $targetVersion);
    }

    public function getReleaseAssetUrlsForChannel(string $assetName, string $channel, ?string $targetVersion = null): ?array
    {
        if ($this->repository === '') {
            return null;
        }

        $channel = $this->normalizeChannel($channel);
        $releases = $this->fetchReleases();
        if ($releases === null) {
            return null;
        }

        $candidate = $this->selectLatestReleaseAsset($releases, $channel, $assetName, $targetVersion);
        if ($candidate === null) {
            return null;
        }

        return [
            'version' => $candidate['tag'],
            'download_url' => $candidate['download_url'],
            'checksums_url' => $candidate['checksums_url'],
            'signature_url' => $candidate['signature_url'],
            'asset_name' => $assetName,
        ];
    }

    public function isUpdateAvailable(?string $currentVersion, ?string $latestVersion = null): ?bool
    {
        $current = $this->normalizeVersion($currentVersion);
        $latest = $this->normalizeVersion($latestVersion ?? $this->getLatestVersion());

        if ($current === null || $latest === null) {
            return null;
        }

        return version_compare($current, $latest, '<');
    }

    /** @return array<string, string> */
    public static function channels(): array
    {
        return [
            self::CHANNEL_STABLE => 'Stable',
            self::CHANNEL_BETA => 'Beta',
            self::CHANNEL_ALPHA => 'Alpha',
        ];
    }

    private function fetchLatestVersion(string $channel): ?string
    {
        $releases = $this->fetchReleases();
        if ($releases === null) {
            return null;
        }

        $latestTag = null;
        foreach ($this->filterReleasesByChannel($releases, $channel) as $release) {
            $tag = $this->extractReleaseTag($release);
            if ($tag === null) {
                continue;
            }

            if ($latestTag === null || $this->compareReleaseTags($tag, $latestTag) > 0) {
                $latestTag = $tag;
            }
        }

        return $latestTag;
    }

    private function fetchReleases(): ?array
    {
        $url = sprintf('https://api.github.com/repos/%s/releases?per_page=20', $this->repository);
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => [
                    'Accept: application/vnd.github+json',
                    'User-Agent: Easy-Wi-NextGen',
                ],
                'timeout' => 5,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return null;
        }

        try {
            $payload = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($payload)) {
            return null;
        }

        return $payload;
    }

    private function normalizeVersion(?string $version): ?string
    {
        if ($version === null) {
            return null;
        }

        $value = trim($version);
        if ($value === '') {
            return null;
        }

        $value = ltrim($value, 'vV');
        $value = preg_replace('/[^0-9A-Za-z.+-]/', '', $value) ?? $value;

        if ($value === '') {
            return null;
        }

        return $value;
    }

    private function normalizeChannel(string $channel): string
    {
        return match (strtolower(trim($channel))) {
            self::CHANNEL_BETA => self::CHANNEL_BETA,
            self::CHANNEL_ALPHA => self::CHANNEL_ALPHA,
            default => self::CHANNEL_STABLE,
        };
    }

    private function findAssetDownloadUrl(array $assets, string $assetName): ?string
    {
        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }

            $name = $asset['name'] ?? null;
            if (!is_string($name) || $name !== $assetName) {
                continue;
            }

            $url = $asset['browser_download_url'] ?? null;
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        return null;
    }

    /**
     * @param array<int, mixed> $releases
     *
     * @return array<int, array<string, mixed>>
     */
    private function filterReleasesByChannel(array $releases, string $channel): array
    {
        $filtered = [];

        foreach ($releases as $release) {
            if (!is_array($release)) {
                continue;
            }

            if (($release['draft'] ?? false) === true) {
                continue;
            }

            $isPrerelease = ($release['prerelease'] ?? false) === true;
            $tagLower = strtolower((string) ($release['tag_name'] ?? ''));

            if ($channel === self::CHANNEL_STABLE) {
                if ($isPrerelease) {
                    continue;
                }
            } elseif ($channel === self::CHANNEL_BETA) {
                // beta: prerelease releases that are NOT alpha-tagged
                if (!$isPrerelease) {
                    continue;
                }
                if (str_contains($tagLower, 'alpha')) {
                    continue;
                }
            } elseif ($channel === self::CHANNEL_ALPHA) {
                // alpha: all prerelease releases (including alpha-tagged)
                if (!$isPrerelease) {
                    continue;
                }
            }

            $filtered[] = $release;
        }

        return $filtered;
    }

    private function extractReleaseTag(array $release): ?string
    {
        $tag = $release['tag_name'] ?? $release['name'] ?? null;
        if (!is_string($tag)) {
            return null;
        }

        $tag = trim($tag);
        return $tag !== '' ? $tag : null;
    }

    private function compareReleaseTags(string $leftTag, string $rightTag): int
    {
        $leftNormalized = $this->normalizeVersion($leftTag);
        $rightNormalized = $this->normalizeVersion($rightTag);

        if ($leftNormalized !== null && $rightNormalized !== null) {
            return version_compare($leftNormalized, $rightNormalized);
        }

        return strcmp($leftTag, $rightTag);
    }

    /**
     * @param array<int, mixed> $releases
     *
     * @return array{tag:string,download_url:string,checksums_url:string,signature_url:?string}|null
     */
    private function selectLatestReleaseAsset(array $releases, string $channel, string $assetName, ?string $targetVersion = null): ?array
    {
        $selected = null;

        foreach ($this->filterReleasesByChannel($releases, $channel) as $release) {
            $assets = $release['assets'] ?? null;
            if (!is_array($assets)) {
                continue;
            }

            $downloadUrl = $this->findAssetDownloadUrl($assets, $assetName);
            if ($downloadUrl === null) {
                continue;
            }

            $checksumsUrl = $this->findAssetDownloadUrl($assets, 'checksums-agent.txt');
            if ($checksumsUrl === null) {
                continue;
            }

            $tag = $this->extractReleaseTag($release);
            if ($tag === null) {
                continue;
            }

            $candidate = [
                'tag' => $tag,
                'download_url' => $downloadUrl,
                'checksums_url' => $checksumsUrl,
                'signature_url' => $this->findAssetDownloadUrl($assets, 'checksums-agent.txt.asc'),
            ];

            $normalizedTarget = $this->normalizeVersion($targetVersion);
            if ($normalizedTarget !== null) {
                $normalizedTag = $this->normalizeVersion($tag);
                if ($normalizedTag !== null && $normalizedTag === $normalizedTarget) {
                    return $candidate;
                }
                continue;
            }

            if ($selected === null || $this->compareReleaseTags($candidate['tag'], $selected['tag']) > 0) {
                $selected = $candidate;
            }
        }

        return $selected;
    }
}
