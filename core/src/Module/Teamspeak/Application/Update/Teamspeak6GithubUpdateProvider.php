<?php

declare(strict_types=1);

namespace App\Module\Teamspeak\Application\Update;

use App\Infrastructure\Config\JsonArray;
use App\Infrastructure\Config\JsonMap;
use App\Infrastructure\Config\ScalarValue;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class Teamspeak6GithubUpdateProvider implements TeamspeakUpdateProviderInterface
{
    private const RELEASE_URL = 'https://api.github.com/repos/teamspeak/teamspeak6-server/releases';
    private const CACHE_KEY = 'teamspeak6_github_releases_v2';

    public function __construct(private readonly HttpClientInterface $httpClient, private readonly ?string $githubToken = null, private readonly ?TeamspeakChecksumResolver $checksumResolver = null, private readonly ?CacheItemPoolInterface $cache = null, private readonly int $cacheTtlSeconds = 900)
    {
    }

    #[\Override]
    public function supports(string $serverType): bool
    {
        return $serverType === 'ts6';
    }

    #[\Override]
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

        $assetNotFoundResult = null;

        foreach ($releases as $release) {
            $isPre = (bool) ($release['prerelease'] ?? false);
            if ($channel !== 'beta' && $isPre) { continue; }
            $releaseTag = ScalarValue::text($release['tag_name'] ?? '');
            $tag = TeamspeakVersionNormalizer::normalize($releaseTag);
            if ($tag === null) { continue; }
            $assets = self::assetList($release['assets'] ?? null);
            $asset = $this->selectAsset($assets, $os, $arch);
            if ($asset === null) {
                // The highest-version release does not have an asset for this platform.
                // Keep searching older releases that may have one; record this result
                // so we can surface it if no compatible asset is found at all.
                $assetNotFoundResult ??= new UpdateResult('ts6', $normalizedInstalledVersion, $tag, false, 'asset_not_found', 'teamspeak.update.asset_not_found_details', [
                    'release_tag' => $releaseTag,
                    'os' => strtolower($os),
                    'arch' => strtolower($arch),
                    'assets' => implode(', ', array_values(array_filter(array_map(static fn(array $a): string => ScalarValue::text($a['name'] ?? ''), $assets)))),
                ], null, null, null, $releaseTag);
                continue;
            }
            $resolver = $this->checksumResolver ?? new TeamspeakChecksumResolver();
            $checksum = $resolver->resolve($asset, $assets, ScalarValue::text($release['body'] ?? ''));
            $assetUrl = is_string($asset['browser_download_url'] ?? null) ? trim((string) $asset['browser_download_url']) : '';
            if ($assetUrl === '') {
                return new UpdateResult('ts6', $normalizedInstalledVersion, $tag, false, 'asset_url_invalid', 'teamspeak.update.asset_url_invalid');
            }
            $available = version_compare($tag, $normalizedInstalledVersion, '>');
            return new UpdateResult('ts6', $normalizedInstalledVersion, $tag, $available, $available ? 'update_available' : 'up_to_date', null, [], $assetUrl, ScalarValue::text($asset['name'] ?? null), self::assetSize($asset['size'] ?? null), $releaseTag, ScalarValue::text($release['body'] ?? null), $checksum);
        }

        return $assetNotFoundResult ?? new UpdateResult('ts6', $normalizedInstalledVersion, null, false, 'no_update_available', 'teamspeak.update.no_update_available');
    }

    public function resolveLatestAssetUrl(string $os, string $arch, string $channel = 'beta'): ?string
    {
        $releases = $this->fetchReleases();
        if ($releases === null) { return null; }

        foreach ($releases as $release) {
            $isPre = (bool) ($release['prerelease'] ?? false);
            if ($channel !== 'beta' && $isPre) { continue; }
            $assets = self::assetList($release['assets'] ?? null);
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
                    $cached = JsonArray::fromValue($item->get());
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
            if ($this->githubToken !== null && $this->githubToken !== '') { $headers['Authorization'] = 'Bearer '.$this->githubToken; }
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

    /**
     * @param array<array-key, mixed> $releases
     * @return list<array<string,mixed>>
     */
    private function sortReleases(array $releases): array
    {
        $normalized = [];
        foreach (array_keys($releases) as $key) {
            $release = JsonMap::fromValue($releases[$key]);
            if ($release !== null) {
                $normalized[] = $release;
            }
        }
        usort($normalized, static function (array $a, array $b): int {
            $versionA = TeamspeakVersionNormalizer::normalize(ScalarValue::text($a['tag_name'] ?? '')) ?? '0.0.0';
            $versionB = TeamspeakVersionNormalizer::normalize(ScalarValue::text($b['tag_name'] ?? '')) ?? '0.0.0';
            $cmp = version_compare($versionB, $versionA);
            if ($cmp !== 0) { return $cmp; }

            return strcmp(ScalarValue::text($b['published_at'] ?? $b['created_at'] ?? ''), ScalarValue::text($a['published_at'] ?? $a['created_at'] ?? ''));
        });

        return $normalized;
    }

    /**
     * @param array<array-key, array<string, mixed>> $assets
     * @return array<string, mixed>|null
     */
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
            $name = strtolower(ScalarValue::text($asset['name'] ?? ''));
            $url = ScalarValue::text($asset['browser_download_url'] ?? '');
            if ($name === '' || !$this->containsAny($name, $osNeedles) || !$this->containsAny($name, $archNeedles)) { continue; }
            if (!str_starts_with($url, 'https://github.com/teamspeak/teamspeak6-server/')) { continue; }
            return $asset;
        }
        return null;
    }

    private static function assetSize(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /** @return list<array<string, mixed>> */
    private static function assetList(mixed $value): array
    {
        $raw = JsonArray::orEmpty($value);
        $assets = [];
        foreach (array_keys($raw) as $key) {
            $asset = JsonMap::fromValue($raw[$key]);
            if ($asset !== null) {
                $assets[] = $asset;
            }
        }
        return $assets;
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
