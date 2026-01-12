<?php

declare(strict_types=1);

namespace App\Service;

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

    public function getReleaseAssetUrls(string $assetName): ?array
    {
        if ($this->repository === '') {
            return null;
        }

        $channel = $this->normalizeChannel($this->channel);
        $releases = $this->fetchReleases();
        if ($releases === null) {
            return null;
        }

        foreach ($releases as $release) {
            if (!is_array($release)) {
                continue;
            }

            $isPrerelease = $release['prerelease'] ?? false;
            if ($channel === self::CHANNEL_STABLE && $isPrerelease) {
                continue;
            }
            if ($channel === self::CHANNEL_BETA && !$isPrerelease) {
                continue;
            }

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

            $tag = $release['tag_name'] ?? $release['name'] ?? null;
            if (!is_string($tag) || $tag === '') {
                continue;
            }

            return [
                'version' => $tag,
                'download_url' => $downloadUrl,
                'checksums_url' => $checksumsUrl,
                'asset_name' => $assetName,
            ];
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
        $releases = $this->fetchReleases();
        if ($releases === null) {
            return null;
        }

        foreach ($releases as $release) {
            if (!is_array($release)) {
                continue;
            }

            $isPrerelease = $release['prerelease'] ?? false;
            if ($channel === self::CHANNEL_STABLE && $isPrerelease) {
                continue;
            }
            if ($channel === self::CHANNEL_BETA && !$isPrerelease) {
                continue;
            }

            $tag = $release['tag_name'] ?? $release['name'] ?? null;
            if (!is_string($tag) || $tag === '') {
                continue;
            }

            return $tag;
        }

        return null;
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
}
