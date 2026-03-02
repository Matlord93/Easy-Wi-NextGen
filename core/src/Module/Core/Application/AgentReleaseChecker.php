<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use Psr\Cache\CacheItemPoolInterface;

final class AgentReleaseChecker
{
    private const CACHE_KEY = 'agent.latest_release_version';

    private const CHANNEL_STABLE = 'stable';
    private const CHANNEL_BETA = 'beta';

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly string $repository,
        private readonly int $cacheTtlSeconds = 300,
        private readonly string $channel = self::CHANNEL_STABLE,
        private readonly string $updateFeedUrl = '',
    ) {
    }

    public function getRepository(): string
    {
        return $this->repository;
    }

    public function getLatestVersion(): ?string
    {
        if ($this->repository === '') {
            return null;
        }

        $channel = $this->normalizeChannel($this->channel);
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
        if ($this->repository === '') {
            $feedOnly = $this->getFeedReleaseAssetUrls($assetName, $targetVersion);
            if ($feedOnly !== null) {
                return $feedOnly;
            }
            return null;
        }

        $feedRelease = $this->getFeedReleaseAssetUrls($assetName, $targetVersion);
        if ($feedRelease !== null) {
            return $feedRelease;
        }

        $channel = $this->normalizeChannel($this->channel);
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

    private function getFeedReleaseAssetUrls(string $assetName, ?string $targetVersion): ?array
    {
        $feed = $this->fetchFeed();
        if ($feed === null) {
            return null;
        }

        $agent = $feed['agent'] ?? null;
        if (!is_array($agent) || !is_array($agent['releases'] ?? null)) {
            return null;
        }

        $channel = $this->normalizeChannel($this->channel);
        $target = $this->normalizeVersion($targetVersion);
        $selected = null;

        foreach ($agent['releases'] as $release) {
            if (!is_array($release)) {
                continue;
            }
            if (!is_string($release['channel'] ?? null) || $this->normalizeChannel((string) $release['channel']) !== $channel) {
                continue;
            }
            $version = $this->normalizeVersion(is_string($release['version'] ?? null) ? $release['version'] : null);
            if ($version === null) {
                continue;
            }
            if ($target !== null && $version !== $target) {
                continue;
            }

            $artifacts = $release['artifacts'] ?? null;
            if (!is_array($artifacts)) {
                continue;
            }
            $artifact = $this->resolveFeedArtifact($artifacts, $assetName);
            if ($artifact === null) {
                continue;
            }

            $candidate = [
                'version' => $version,
                'download_url' => $artifact['url'],
                'checksums_url' => is_array($release['manifest'] ?? null) ? (($release['manifest']['url'] ?? '') ?: '') : '',
                'signature_url' => is_string($release['signature'] ?? null) ? $release['signature'] : null,
                'asset_name' => $assetName,
            ];

            if ($target !== null) {
                return $candidate;
            }

            if ($selected === null || version_compare($version, $selected['version'], '>')) {
                $selected = $candidate;
            }
        }

        return $selected;
    }

    /**
     * @param array<string, mixed> $artifacts
     * @return array{url:string}|null
     */
    private function resolveFeedArtifact(array $artifacts, string $assetName): ?array
    {
        $keyMap = [
            'easywi-agent-linux-amd64' => ['linux_amd64_targz', 'linux_amd64_zip'],
            'easywi-agent-windows-amd64.exe' => ['windows_amd64_zip'],
            'easywi-agent-linux-arm64' => ['linux_arm64_targz', 'linux_arm64_zip'],
        ];
        $keys = $keyMap[$assetName] ?? [$assetName];
        foreach ($keys as $key) {
            $artifact = $artifacts[$key] ?? null;
            if (!is_array($artifact)) {
                continue;
            }
            $url = $artifact['url'] ?? null;
            if (is_string($url) && $url !== '') {
                return ['url' => $url];
            }
        }

        return null;
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

    public function getChannel(): string
    {
        return $this->normalizeChannel($this->channel);
    }

    private function fetchLatestVersion(string $channel): ?string
    {
        $feed = $this->fetchFeed();
        if ($feed !== null) {
            $latest = $this->latestFromFeed($feed, $channel);
            if ($latest !== null) {
                return $latest;
            }
        }

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

    /**
     * @return array<string, mixed>|null
     */
    private function fetchFeed(): ?array
    {
        if ($this->updateFeedUrl === '') {
            return null;
        }

        $etagKey = self::CACHE_KEY . '.feed.etag';
        $payloadKey = self::CACHE_KEY . '.feed.payload';
        $etagItem = $this->cache->getItem($etagKey);
        $payloadItem = $this->cache->getItem($payloadKey);

        $headers = [
            'Accept: application/json',
            'User-Agent: Easy-Wi-NextGen',
        ];
        $etag = $etagItem->isHit() && is_string($etagItem->get()) ? trim((string) $etagItem->get()) : '';
        if ($etag !== '') {
            $headers[] = 'If-None-Match: ' . $etag;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => $headers,
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($this->updateFeedUrl, false, $context);
        $statusLine = is_array($http_response_header ?? null) ? (string) ($http_response_header[0] ?? '') : '';
        if (str_contains($statusLine, ' 304 ') && $payloadItem->isHit() && is_array($payloadItem->get())) {
            /** @var array<string, mixed> $cached */
            $cached = $payloadItem->get();
            return $cached;
        }

        if ($response === false) {
            if ($payloadItem->isHit() && is_array($payloadItem->get())) {
                /** @var array<string, mixed> $cached */
                $cached = $payloadItem->get();
                return $cached;
            }
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

        $payloadItem->set($payload);
        $payloadItem->expiresAfter($this->cacheTtlSeconds);
        $this->cache->save($payloadItem);

        foreach (($http_response_header ?? []) as $headerLine) {
            if (!is_string($headerLine) || stripos($headerLine, 'ETag:') !== 0) {
                continue;
            }
            $etagValue = trim(substr($headerLine, 5));
            if ($etagValue !== '') {
                $etagItem->set($etagValue);
                $etagItem->expiresAfter($this->cacheTtlSeconds);
                $this->cache->save($etagItem);
            }
            break;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $feed
     */
    private function latestFromFeed(array $feed, string $channel): ?string
    {
        $agent = $feed['agent'] ?? null;
        if (!is_array($agent)) {
            return null;
        }

        $latest = $agent['latest'] ?? null;
        if (is_array($latest) && is_string($latest[$channel] ?? null)) {
            $candidate = $this->normalizeVersion((string) $latest[$channel]);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        $releases = $agent['releases'] ?? null;
        if (!is_array($releases)) {
            return null;
        }

        $latestVersion = null;
        foreach ($releases as $release) {
            if (!is_array($release)) {
                continue;
            }
            if (($release['channel'] ?? '') !== $channel) {
                continue;
            }
            $version = $this->normalizeVersion(is_string($release['version'] ?? null) ? $release['version'] : null);
            if ($version === null) {
                continue;
            }
            if ($latestVersion === null || version_compare($version, $latestVersion, '>')) {
                $latestVersion = $version;
            }
        }

        return $latestVersion;
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
        $value = strtolower(trim($channel));
        if ($value === self::CHANNEL_BETA) {
            return self::CHANNEL_BETA;
        }

        return self::CHANNEL_STABLE;
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
            if ($channel === self::CHANNEL_STABLE && $isPrerelease) {
                continue;
            }
            if ($channel === self::CHANNEL_BETA && !$isPrerelease) {
                continue;
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
