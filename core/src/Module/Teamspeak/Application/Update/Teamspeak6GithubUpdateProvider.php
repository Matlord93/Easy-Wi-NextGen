<?php

declare(strict_types=1);

namespace App\Module\Teamspeak\Application\Update;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class Teamspeak6GithubUpdateProvider implements TeamspeakUpdateProviderInterface
{
    private const RELEASE_URL = 'https://api.github.com/repos/teamspeak/teamspeak6-server/releases';
    private const CACHE_KEY = 'teamspeak6_github_releases_v2';

    public function __construct(private readonly HttpClientInterface $httpClient, private readonly ?string $githubToken = null, private readonly ?TeamspeakChecksumResolver $checksumResolver = null, private readonly ?CacheItemPoolInterface $cache = null, private readonly int $cacheTtlSeconds = 900)
    {
    }

    public function supports(string $serverType): bool
    {
        return $serverType === 'ts6';
    }

    public function checkForUpdates(string $installedVersion, string $os, string $arch, string $channel = 'stable'): UpdateResult
    {
        $normalizedInstalledVersion = TeamspeakVersionNormalizer::normalize($installedVersion);
        if ($normalizedInstalledVersion === null) {
            return new UpdateResult('ts6', $installedVersion, null, false, 'version_invalid', 'teamspeak.update.version_invalid');
        }
        $releases = $this->fetchReleases();
        if ($releases === null) {
            return new UpdateResult('ts6', $normalizedInstalledVersion, null, false, 'github_unreachable', 'teamspeak.update.github_unreachable');
        }

        foreach ($releases as $release) {
            $isPre = (bool) ($release['prerelease'] ?? false);
            if ($channel !== 'beta' && $isPre) { continue; }
            $releaseTag = (string) ($release['tag_name'] ?? '');
            $tag = TeamspeakVersionNormalizer::normalize($releaseTag);
            if ($tag === '') { continue; }
            $assets = (array) ($release['assets'] ?? []);
            $asset = $this->selectAsset($assets, $os, $arch);
            if ($asset === null) {
                return new UpdateResult('ts6', $normalizedInstalledVersion, $tag, false, 'asset_not_found', 'teamspeak.update.asset_not_found_details', [
                    'release_tag' => $releaseTag,
                    'os' => strtolower($os),
                    'arch' => strtolower($arch),
                    'assets' => implode(', ', array_values(array_filter(array_map(static fn(array $a): string => (string) ($a['name'] ?? ''), $assets)))),
                ], null, null, null, $releaseTag);
            }
            $resolver = $this->checksumResolver ?? new TeamspeakChecksumResolver();
            $checksum = $resolver->resolve($asset, $assets, (string) ($release['body'] ?? ''));
            $assetUrl = is_string($asset['browser_download_url'] ?? null) ? trim((string) $asset['browser_download_url']) : '';
            if ($assetUrl === '') {
                return new UpdateResult('ts6', $normalizedInstalledVersion, $tag, false, 'asset_url_invalid', 'teamspeak.update.asset_url_invalid');
            }
            $available = version_compare($tag, $normalizedInstalledVersion, '>');
            return new UpdateResult('ts6', $normalizedInstalledVersion, $tag, $available, $available ? 'update_available' : 'up_to_date', null, [], $assetUrl, (string) ($asset['name'] ?? null), isset($asset['size']) ? (int) $asset['size'] : null, $releaseTag, (string) ($release['body'] ?? null), $checksum);
        }

        return new UpdateResult('ts6', $normalizedInstalledVersion, null, false, 'no_update_available', 'teamspeak.update.no_update_available');
    }

    public function resolveLatestAssetUrl(string $os, string $arch, string $channel = 'beta'): ?string
    {
        $releases = $this->fetchReleases();
        if ($releases === null) { return null; }

        foreach ($releases as $release) {
            $isPre = (bool) ($release['prerelease'] ?? false);
            if ($channel !== 'beta' && $isPre) { continue; }
            $assets = (array) ($release['assets'] ?? []);
            $asset = $this->selectAsset($assets, $os, $arch);
            if ($asset === null) { continue; }
            $url = is_string($asset['browser_download_url'] ?? null) ? trim((string) $asset['browser_download_url']) : '';
            if ($url !== '') { return $url; }
        }

        return null;
    }

    /** @return list<array<string,mixed>>|null null when GitHub is unreachable */
    private function fetchReleases(int $timeout = 5): ?array
    {
        if ($this->cache !== null) {
            try {
                $item = $this->cache->getItem(self::CACHE_KEY);
                if ($item->isHit()) {
                    $cached = $item->get();
                    if (is_array($cached)) {
                        return $this->sortReleases($cached);
                    }
                }
            } catch (\Throwable) {
                // Cache must never block update checks.
            }
        }

        try {
            $headers = ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'Easy-WI-TS6-Updater'];
            if ($this->githubToken) { $headers['Authorization'] = 'Bearer '.$this->githubToken; }
            $releases = $this->httpClient->request('GET', self::RELEASE_URL, ['headers' => $headers, 'timeout' => $timeout, 'query' => ['per_page' => 100]])->toArray();
            $releases = $this->sortReleases($releases);

            if ($this->cache !== null) {
                try {
                    $item = $this->cache->getItem(self::CACHE_KEY);
                    $item->set($releases);
                    $item->expiresAfter(max(60, $this->cacheTtlSeconds));
                    $this->cache->save($item);
                } catch (\Throwable) {
                    // Cache must never block update checks.
                }
            }

            return $releases;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<int,array<string,mixed>> $releases @return list<array<string,mixed>> */
    private function sortReleases(array $releases): array
    {
        $normalized = array_values(array_filter($releases, static fn(mixed $release): bool => is_array($release)));
        usort($normalized, static function (array $a, array $b): int {
            $versionA = TeamspeakVersionNormalizer::normalize((string) ($a['tag_name'] ?? '')) ?? '0.0.0';
            $versionB = TeamspeakVersionNormalizer::normalize((string) ($b['tag_name'] ?? '')) ?? '0.0.0';
            $cmp = version_compare($versionB, $versionA);
            if ($cmp !== 0) { return $cmp; }

            return strcmp((string) ($b['published_at'] ?? $b['created_at'] ?? ''), (string) ($a['published_at'] ?? $a['created_at'] ?? ''));
        });

        return $normalized;
    }

    /** @param array<int,array<string,mixed>> $assets */
    public function selectAsset(array $assets, string $os, string $arch): ?array
    {
        $osNeedles = match (strtolower($os)) {
            'linux' => ['linux'],
            'windows', 'win' => ['win', 'windows'],
            default => [strtolower($os)],
        };
        $archNeedles = match (strtolower($arch)) {
            'amd64', 'x64', 'x86_64' => ['amd64', 'x64', 'x86_64'],
            'arm64', 'aarch64' => ['arm64', 'aarch64'],
            default => [strtolower($arch)],
        };
        foreach ($assets as $asset) {
            $name = strtolower((string) ($asset['name'] ?? ''));
            $url = (string) ($asset['browser_download_url'] ?? '');
            if ($name === '' || !$this->containsAny($name, $osNeedles) || !$this->containsAny($name, $archNeedles)) { continue; }
            if (!str_starts_with($url, 'https://github.com/teamspeak/teamspeak6-server/')) { continue; }
            return $asset;
        }
        return null;
    }

    /** @param array<int,string> $needles */
    private function containsAny(string $value, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($value, $needle)) {
                return true;
            }
        }

        return false;
    }
}
