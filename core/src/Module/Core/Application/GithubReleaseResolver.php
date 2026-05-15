<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class GithubReleaseResolver
{
    public const CHANNEL_STABLE = 'stable';
    public const CHANNEL_BETA = 'beta';
    public const CHANNEL_DEV = 'dev';
    public const CHANNEL_ALPHA = 'alpha';

    private const DEFAULT_CACHE_TTL_SECONDS = 3600;
    private const MANUAL_REFRESH_LOCK_SECONDS = 60;

    public const ERROR_RATE_LIMIT = 'RATE_LIMIT';
    public const ERROR_ACCESS_DENIED = 'ACCESS_DENIED';
    public const ERROR_NOT_FOUND = 'NOT_FOUND';
    public const ERROR_TEMPORARY_GITHUB_ERROR = 'TEMPORARY_GITHUB_ERROR';
    public const ERROR_PRIVATE_ASSET = 'PRIVATE_ASSET';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ?string $token = null,
        private readonly ?CacheItemPoolInterface $cache = null,
        private readonly int $cacheTtlSeconds = self::DEFAULT_CACHE_TTL_SECONDS,
        private readonly ?string $lockDir = null,
    ) {
    }

    public function getLatestVersion(string $repository, string $channel, string $checkType = 'release', bool $force = false): ?string
    {
        $releases = $this->fetchReleases($repository, $channel, $checkType, $force);
        if ($releases === null) {
            return null;
        }

        $selected = $this->selectLatestRelease($releases, $channel);
        if ($selected !== null) {
            $this->storeSelectedCacheMetadata($repository, $channel, $checkType, ['release' => $selected]);
        }

        return $selected !== null ? $this->extractReleaseTag($selected) : null;
    }

    /**
     * @return array{version:string,download_url:string,checksums_url:string,signature_url:?string,asset_name:string,channel:string}|null
     */
    public function getLatestAsset(
        string $repository,
        string $channel,
        string $assetName,
        string $checksumsAssetName,
        ?string $signatureAssetName = null,
        ?string $targetVersion = null,
        string $checkType = 'release',
        bool $force = false,
    ): ?array {
        return $this->getLatestAssetMatching(
            $repository,
            $channel,
            static fn (string $candidateName, string $releaseTag): bool => $candidateName === $assetName,
            $checksumsAssetName,
            $signatureAssetName,
            $targetVersion,
            $checkType,
            $force,
        );
    }

    /**
     * @param callable(string,string):bool|int $assetMatcher receives the asset name and release tag. Return true to accept or a lower integer for higher priority.
     *
     * @return array{version:string,download_url:string,checksums_url:string,signature_url:?string,asset_name:string,channel:string}|null
     */
    public function getLatestAssetMatching(
        string $repository,
        string $channel,
        callable $assetMatcher,
        string $checksumsAssetName,
        ?string $signatureAssetName = null,
        ?string $targetVersion = null,
        string $checkType = 'release',
        bool $force = false,
    ): ?array {
        $releases = $this->fetchReleases($repository, $channel, $checkType, $force);
        if ($releases === null) {
            return null;
        }

        $selected = $this->selectLatestAssetMatching($releases, $channel, $assetMatcher, $checksumsAssetName, $signatureAssetName, $targetVersion);
        if ($selected !== null) {
            $this->storeSelectedCacheMetadata($repository, $channel, $checkType, ['asset' => $selected]);
        }

        return $selected;
    }

    /**
     * @param callable(string,string):bool|int $assetMatcher receives the asset name and release tag.
     */
    public function describeLatestAssetSelectionFailure(
        string $repository,
        string $channel,
        callable $assetMatcher,
        string $checksumsAssetName,
        ?string $targetVersion = null,
        string $checkType = 'release',
        bool $force = false,
    ): string {
        $channel = $this->normalizeChannel($channel);
        $releases = $this->fetchReleases($repository, $channel, $checkType, $force);
        if ($releases === null) {
            return sprintf('Repository %s konnte nicht abgefragt werden.', $repository);
        }

        $normalizedTarget = $this->normalizeVersion($targetVersion);
        $latestRelease = null;
        foreach ($this->filterReleasesByChannel($releases, $channel) as $release) {
            $tag = $this->extractReleaseTag($release);
            if ($tag === null) {
                continue;
            }
            if ($normalizedTarget !== null && $this->normalizeVersion($tag) !== $normalizedTarget) {
                continue;
            }
            if ($latestRelease === null || $this->compareReleaseTags($tag, (string) ($latestRelease['tag_name'] ?? $latestRelease['name'] ?? '')) > 0) {
                $latestRelease = $release;
            }
        }

        if ($latestRelease === null) {
            return sprintf('Kein Release für Repository %s im Channel %s gefunden.', $repository, $channel);
        }

        $tag = $this->extractReleaseTag($latestRelease) ?? '(unbekannt)';
        $assets = is_array($latestRelease['assets'] ?? null) ? $latestRelease['assets'] : [];
        $assetNames = [];
        foreach ($assets as $asset) {
            if (is_array($asset) && is_string($asset['name'] ?? null) && trim($asset['name']) !== '') {
                $assetNames[] = trim($asset['name']);
            }
        }

        $matching = $this->findMatchingAssetName($assets, $tag, $assetMatcher);
        $hasChecksums = $this->findAssetDownloadUrl($assets, $checksumsAssetName) !== null;
        if ($matching !== null && !$hasChecksums) {
            return sprintf(
                'Release %s enthält Core-Paket %s, aber %s fehlt. Gefunden: %s',
                $tag,
                $matching,
                $checksumsAssetName,
                implode(', ', $assetNames),
            );
        }

        return sprintf(
            'Kein gültiges Core-Paket im Release %s gefunden. Gefunden: %s',
            $tag,
            implode(', ', $assetNames) !== '' ? implode(', ', $assetNames) : '(keine Assets)',
        );
    }

    /**
     * @param array<int, mixed> $releases
     *
     * @return array<string, mixed>|null
     */
    public function selectLatestRelease(array $releases, string $channel): ?array
    {
        $selected = null;
        foreach ($this->filterReleasesByChannel($releases, $channel) as $release) {
            $tag = $this->extractReleaseTag($release);
            if ($tag === null) {
                continue;
            }

            if ($selected === null) {
                $selected = $release;
                continue;
            }

            $selectedTag = $this->extractReleaseTag($selected);
            if ($selectedTag === null || $this->compareReleaseTags($tag, $selectedTag) > 0) {
                $selected = $release;
            }
        }

        return $selected;
    }

    /**
     * @param array<int, mixed> $releases
     *
     * @return array{version:string,download_url:string,checksums_url:string,signature_url:?string,asset_name:string,channel:string}|null
     */
    public function selectLatestAsset(
        array $releases,
        string $channel,
        string $assetName,
        string $checksumsAssetName,
        ?string $signatureAssetName = null,
        ?string $targetVersion = null,
        string $checkType = 'release',
        bool $force = false,
    ): ?array {
        return $this->selectLatestAssetMatching(
            $releases,
            $channel,
            static fn (string $candidateName, string $releaseTag): bool => $candidateName === $assetName,
            $checksumsAssetName,
            $signatureAssetName,
            $targetVersion,
        );
    }

    /**
     * @param array<int, mixed> $releases
     * @param callable(string,string):bool|int $assetMatcher receives the asset name and release tag. Return true to accept or a lower integer for higher priority.
     *
     * @return array{version:string,download_url:string,checksums_url:string,signature_url:?string,asset_name:string,channel:string}|null
     */
    public function selectLatestAssetMatching(
        array $releases,
        string $channel,
        callable $assetMatcher,
        string $checksumsAssetName,
        ?string $signatureAssetName = null,
        ?string $targetVersion = null,
        string $checkType = 'release',
        bool $force = false,
    ): ?array {
        $channel = $this->normalizeChannel($channel);
        $selected = null;
        $normalizedTarget = $this->normalizeVersion($targetVersion);

        foreach ($this->filterReleasesByChannel($releases, $channel) as $release) {
            $tag = $this->extractReleaseTag($release);
            if ($tag === null) {
                continue;
            }

            if ($normalizedTarget !== null && $this->normalizeVersion($tag) !== $normalizedTarget) {
                continue;
            }

            $assets = is_array($release['assets'] ?? null) ? $release['assets'] : [];
            $matchedAssetName = $this->findMatchingAssetName($assets, $tag, $assetMatcher);
            $downloadUrl = $matchedAssetName !== null ? $this->findAssetDownloadUrl($assets, $matchedAssetName) : null;
            $checksumsUrl = $this->findAssetDownloadUrl($assets, $checksumsAssetName);
            if ($matchedAssetName === null || $downloadUrl === null || $checksumsUrl === null) {
                continue;
            }

            $candidate = [
                'version' => $tag,
                'download_url' => $downloadUrl,
                'checksums_url' => $checksumsUrl,
                'signature_url' => $signatureAssetName !== null ? $this->findAssetDownloadUrl($assets, $signatureAssetName) : null,
                'asset_name' => $matchedAssetName,
                'channel' => $channel,
            ];

            if ($normalizedTarget !== null) {
                return $candidate;
            }

            if ($selected === null || $this->compareReleaseTags($candidate['version'], $selected['version']) > 0) {
                $selected = $candidate;
            }
        }

        return $selected;
    }

    public function detectReleaseChannel(array $release): string
    {
        $explicitChannel = $this->extractExplicitChannel($release);
        if ($explicitChannel !== null) {
            return $explicitChannel;
        }

        $tagLower = strtolower((string) ($release['tag_name'] ?? $release['name'] ?? ''));
        if (($release['prerelease'] ?? false) !== true) {
            return self::CHANNEL_STABLE;
        }

        if (preg_match('/(?:^|[._\-+])(?:dev|alpha|snapshot|nightly)(?:$|[._\-+])/', $tagLower) === 1) {
            return self::CHANNEL_DEV;
        }

        if (preg_match('/(?:^|[._\-+])(?:beta|preview|rc)(?:$|[._\-+])/', $tagLower) === 1) {
            return self::CHANNEL_BETA;
        }

        return self::CHANNEL_BETA;
    }

    public function normalizeChannel(string $channel): string
    {
        return match (strtolower(trim($channel))) {
            self::CHANNEL_BETA => self::CHANNEL_BETA,
            self::CHANNEL_DEV, self::CHANNEL_ALPHA => self::CHANNEL_DEV,
            default => self::CHANNEL_STABLE,
        };
    }

    public function normalizeVersion(?string $version): ?string
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

        return $value !== '' ? $value : null;
    }

    public function compareReleaseTags(string $leftTag, string $rightTag): int
    {
        $leftNormalized = $this->normalizeVersion($leftTag);
        $rightNormalized = $this->normalizeVersion($rightTag);

        if ($leftNormalized !== null && $rightNormalized !== null) {
            $comparison = version_compare(
                $this->normalizeDevReleaseAliasForComparison($leftNormalized),
                $this->normalizeDevReleaseAliasForComparison($rightNormalized),
            );
            if ($comparison !== 0) {
                return $comparison;
            }
        }

        return strcmp($leftTag, $rightTag);
    }

    private function normalizeDevReleaseAliasForComparison(string $version): string
    {
        return preg_replace('/([._\-+])(?:alpha|snapshot|nightly)(?=$|[._\-+])/i', '$1dev', $version) ?? $version;
    }

    /** @return array<int, mixed>|null */
    public function getCachedReleases(string $repository, string $channel, string $checkType, bool $force = false): ?array
    {
        return $this->fetchReleases($repository, $channel, $checkType, $force);
    }

    /** @return array<string, mixed> */
    public function getCacheStatus(string $repository, string $channel, string $checkType): array
    {
        $repository = $this->normalizeRepository($repository);
        $channel = $this->normalizeChannel($channel);
        $checkType = $this->normalizeCheckType($checkType);
        $cached = $this->readCache($repository, $channel, $checkType);
        $now = time();

        return [
            'repository' => $repository,
            'channel' => $channel,
            'check_type' => $checkType,
            'ttl' => $this->effectiveCacheTtlSeconds(),
            'has_cache' => $cached !== null && is_array($cached['releases'] ?? null),
            'fetched_at' => $cached['fetched_at'] ?? null,
            'last_success_at' => $cached['last_success_at'] ?? null,
            'expires_at' => $cached['expires_at'] ?? null,
            'next_check_at' => max((int) ($cached['expires_at'] ?? 0), (int) ($cached['rate_limit_reset'] ?? 0)) ?: null,
            'rate_limit_reset' => $cached['rate_limit_reset'] ?? null,
            'rate_limit_remaining' => $cached['rate_limit_remaining'] ?? null,
            'last_http_status' => $cached['last_http_status'] ?? null,
            'last_error_type' => $cached['last_error_type'] ?? null,
            'repository_visibility' => $cached['repository_visibility'] ?? 'unknown',
            'last_error' => $cached['last_error'] ?? null,
            'is_fresh' => (int) ($cached['expires_at'] ?? 0) > $now,
            'token_present' => $this->resolveToken() !== '',
        ];
    }

    /** @return array<int, mixed>|null */
    private function fetchReleases(string $repository, string $channel = self::CHANNEL_STABLE, string $checkType = 'release', bool $force = false): ?array
    {
        $repository = $this->normalizeRepository($repository);
        $channel = $this->normalizeChannel($channel);
        $checkType = $this->normalizeCheckType($checkType);
        if ($repository === '') {
            return null;
        }

        if ($this->cache === null) {
            try {
                return $this->requestReleases($repository, []);
            } catch (\Throwable) {
                return null;
            }
        }

        $now = time();
        $cached = $this->readCache($repository, $channel, $checkType);
        if (!$force && $cached !== null) {
            $rateLimitReset = (int) ($cached['rate_limit_reset'] ?? 0);
            if ($rateLimitReset > $now && is_array($cached['releases'] ?? null)) {
                return $cached['releases'];
            }
            if ((int) ($cached['expires_at'] ?? 0) > $now && is_array($cached['releases'] ?? null)) {
                return $cached['releases'];
            }
            if ($rateLimitReset > $now) {
                return null;
            }
        }
        if ($force && $cached !== null && (int) ($cached['manual_refresh_locked_until'] ?? 0) > $now && is_array($cached['releases'] ?? null)) {
            return $cached['releases'];
        }

        $lock = $this->acquireCacheLock($repository, $channel, $checkType);
        if ($lock === null) {
            if (is_array($cached['releases'] ?? null)) {
                return $cached['releases'];
            }
            $this->writeCache($repository, $channel, $checkType, $this->mergeCache($cached, [
                'last_error' => 'Updateprüfung läuft bereits.',
            ]));

            return null;
        }

        try {
            $cached = $this->readCache($repository, $channel, $checkType);
            if (!$force && $cached !== null) {
                $rateLimitReset = (int) ($cached['rate_limit_reset'] ?? 0);
                if (($rateLimitReset > time() || (int) ($cached['expires_at'] ?? 0) > time()) && is_array($cached['releases'] ?? null)) {
                    return $cached['releases'];
                }
            }

            $conditionalHeaders = [];
            if (is_string($cached['etag'] ?? null) && $cached['etag'] !== '') {
                $conditionalHeaders['If-None-Match'] = $cached['etag'];
            }
            if (is_string($cached['last_modified'] ?? null) && $cached['last_modified'] !== '') {
                $conditionalHeaders['If-Modified-Since'] = $cached['last_modified'];
            }

            try {
                $requestResult = $this->requestReleasesWithMetadata($repository, $conditionalHeaders);
            } catch (\Throwable $exception) {
                $this->writeCache($repository, $channel, $checkType, $this->mergeCache($cached, [
                    'last_error' => $this->sanitizeError($exception->getMessage()) ?: 'GitHub API konnte nicht abgefragt werden.',
                    'last_error_type' => self::ERROR_TEMPORARY_GITHUB_ERROR,
                    'manual_refresh_locked_until' => $force ? time() + self::MANUAL_REFRESH_LOCK_SECONDS : (int) ($cached['manual_refresh_locked_until'] ?? 0),
                ]));

                return is_array($cached['releases'] ?? null) ? $cached['releases'] : null;
            }

            $rateLimitReset = $requestResult['rate_limit_reset'];
            if ($requestResult['status'] === 304 && is_array($cached['releases'] ?? null)) {
                $entry = $this->mergeCache($cached, [
                    'fetched_at' => time(),
                    'expires_at' => time() + $this->effectiveCacheTtlSeconds(),
                    'rate_limit_reset' => $rateLimitReset,
                    'rate_limit_remaining' => $requestResult['rate_limit_remaining'],
                    'last_http_status' => $requestResult['status'],
                    'last_error_type' => null,
                    'repository_visibility' => $requestResult['repository_visibility'] !== 'unknown' ? $requestResult['repository_visibility'] : ($cached['repository_visibility'] ?? 'unknown'),
                    'last_error' => null,
                    'etag' => $requestResult['etag'] ?? ($cached['etag'] ?? null),
                    'last_modified' => $requestResult['last_modified'] ?? ($cached['last_modified'] ?? null),
                    'manual_refresh_locked_until' => $force ? time() + self::MANUAL_REFRESH_LOCK_SECONDS : (int) ($cached['manual_refresh_locked_until'] ?? 0),
                ]);
                $this->writeCache($repository, $channel, $checkType, $entry);

                return $cached['releases'];
            }

            if ($requestResult['status'] >= 200 && $requestResult['status'] < 300 && is_array($requestResult['releases'])) {
                $entry = [
                    'repository' => $repository,
                    'channel' => $channel,
                    'check_type' => $checkType,
                    'fetched_at' => time(),
                    'last_success_at' => time(),
                    'expires_at' => time() + $this->effectiveCacheTtlSeconds(),
                    'selected' => null,
                    'releases' => $requestResult['releases'],
                    'etag' => $requestResult['etag'],
                    'last_modified' => $requestResult['last_modified'],
                    'rate_limit_reset' => $rateLimitReset,
                    'rate_limit_remaining' => $requestResult['rate_limit_remaining'],
                    'last_http_status' => $requestResult['status'],
                    'last_error_type' => null,
                    'repository_visibility' => $requestResult['repository_visibility'],
                    'last_error' => null,
                    'manual_refresh_locked_until' => $force ? time() + self::MANUAL_REFRESH_LOCK_SECONDS : 0,
                ];
                $this->writeCache($repository, $channel, $checkType, $entry);

                return $requestResult['releases'];
            }

            $this->writeCache($repository, $channel, $checkType, $this->mergeCache($cached, [
                'rate_limit_reset' => $rateLimitReset,
                'rate_limit_remaining' => $requestResult['rate_limit_remaining'],
                'last_http_status' => $requestResult['status'],
                'last_error_type' => $requestResult['error_type'],
                'repository_visibility' => $requestResult['repository_visibility'],
                'last_error' => $this->statusErrorMessage($requestResult['status'], $rateLimitReset),
                'manual_refresh_locked_until' => $force ? time() + self::MANUAL_REFRESH_LOCK_SECONDS : (int) ($cached['manual_refresh_locked_until'] ?? 0),
            ]));

            return is_array($cached['releases'] ?? null) ? $cached['releases'] : null;
        } finally {
            $this->releaseCacheLock($lock);
        }
    }

    /** @return array<int, mixed>|null */
    private function requestReleases(string $repository, array $headers): ?array
    {
        $result = $this->requestReleasesWithMetadata($repository, $headers);
        return $result['status'] >= 200 && $result['status'] < 300 && is_array($result['releases']) ? $result['releases'] : null;
    }

    /** @return array{status:int,releases:mixed,etag:?string,last_modified:?string,rate_limit_reset:?int,rate_limit_remaining:?int,error_type:?string,repository_visibility:string} */
    private function requestReleasesWithMetadata(string $repository, array $extraHeaders): array
    {
        $headers = array_merge([
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => 'Easy-Wi-NextGen',
            'X-GitHub-Api-Version' => '2022-11-28',
        ], $extraHeaders);
        $token = $this->resolveToken();
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $response = $this->httpClient->request('GET', sprintf('https://api.github.com/repos/%s/releases?per_page=50', $repository), [
            'headers' => $headers,
            'timeout' => 10,
        ]);
        $status = $response->getStatusCode();
        $responseHeaders = $response->getHeaders(false);
        $rateLimitReset = $this->firstHeaderInt($responseHeaders, 'x-ratelimit-reset');
        $remaining = $this->firstHeaderInt($responseHeaders, 'x-ratelimit-remaining');
        if ($remaining !== 0) {
            $rateLimitReset = null;
        }

        $payload = null;
        if ($status !== 304) {
            $content = $response->getContent(false);
            if ($status >= 200 && $status < 300) {
                $payload = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            }
        }

        return [
            'status' => $status,
            'releases' => $payload,
            'etag' => $this->firstHeader($responseHeaders, 'etag'),
            'last_modified' => $this->firstHeader($responseHeaders, 'last-modified'),
            'rate_limit_reset' => $rateLimitReset,
            'rate_limit_remaining' => $remaining,
            'error_type' => $this->classifyGithubError($status, $remaining),
            'repository_visibility' => $this->detectRepositoryVisibility($payload),
        ];
    }

    private function normalizeRepository(string $repository): string
    {
        $repository = trim($repository, '/ ');
        return preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $repository) === 1 ? $repository : '';
    }

    private function normalizeCheckType(string $checkType): string
    {
        $checkType = strtolower(trim($checkType));
        $checkType = preg_replace('/[^a-z0-9_.-]+/', '_', $checkType) ?? '';
        return $checkType !== '' ? $checkType : 'release';
    }

    private function effectiveCacheTtlSeconds(): int
    {
        return max(1, $this->cacheTtlSeconds > 0 ? $this->cacheTtlSeconds : self::DEFAULT_CACHE_TTL_SECONDS);
    }

    private function resolveToken(): string
    {
        $token = $this->token !== null ? trim($this->token) : '';
        if ($token === '') {
            $token = trim((string) ($_SERVER['APP_GITHUB_TOKEN'] ?? $_ENV['APP_GITHUB_TOKEN'] ?? $_SERVER['GITHUB_TOKEN'] ?? $_ENV['GITHUB_TOKEN'] ?? ''));
        }
        return $token;
    }

    /** @param array<string, mixed> $selected */
    private function storeSelectedCacheMetadata(string $repository, string $channel, string $checkType, array $selected): void
    {
        $repository = $this->normalizeRepository($repository);
        $channel = $this->normalizeChannel($channel);
        $checkType = $this->normalizeCheckType($checkType);
        $cached = $this->readCache($repository, $channel, $checkType);
        if ($cached === null) {
            return;
        }
        $cached['selected'] = $selected;
        $this->writeCache($repository, $channel, $checkType, $cached);
    }

    private function cacheKey(string $repository, string $channel, string $checkType): string
    {
        return 'github_release.' . sha1($repository . '|' . $channel . '|' . $checkType);
    }

    private function readCache(string $repository, string $channel, string $checkType): ?array
    {
        if ($this->cache === null) {
            return null;
        }
        $item = $this->cache->getItem($this->cacheKey($repository, $channel, $checkType));
        $value = $item->get();
        return $item->isHit() && is_array($value) ? $value : null;
    }

    private function writeCache(string $repository, string $channel, string $checkType, array $entry): void
    {
        if ($this->cache === null) {
            return;
        }
        $entry['repository'] = $repository;
        $entry['channel'] = $channel;
        $entry['check_type'] = $checkType;
        $item = $this->cache->getItem($this->cacheKey($repository, $channel, $checkType));
        $item->set($entry);
        $this->cache->save($item);
    }

    private function mergeCache(?array $cached, array $changes): array
    {
        return array_merge(is_array($cached) ? $cached : [
            'fetched_at' => null,
            'expires_at' => null,
            'releases' => null,
            'etag' => null,
            'last_modified' => null,
            'rate_limit_reset' => null,
            'rate_limit_remaining' => null,
            'last_http_status' => null,
            'last_error_type' => null,
            'repository_visibility' => 'unknown',
            'last_error' => null,
        ], $changes);
    }

    /** @return resource|null */
    private function acquireCacheLock(string $repository, string $channel, string $checkType)
    {
        $dir = $this->lockDir ?? sys_get_temp_dir() . '/easywi-update-cache-locks';
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        $handle = @fopen($dir . '/' . sha1($repository . '|' . $channel . '|' . $checkType) . '.lock', 'c');
        if ($handle === false) {
            return null;
        }
        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            @fclose($handle);
            return null;
        }
        return $handle;
    }

    /** @param resource $lock */
    private function releaseCacheLock($lock): void
    {
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }

    private function firstHeader(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $values) {
            if (strtolower((string) $key) === strtolower($name) && is_array($values) && is_string($values[0] ?? null) && trim($values[0]) !== '') {
                return trim($values[0]);
            }
        }
        return null;
    }

    private function firstHeaderInt(array $headers, string $name): ?int
    {
        $value = $this->firstHeader($headers, $name);
        return $value !== null && ctype_digit($value) ? (int) $value : null;
    }

    private function statusErrorMessage(int $status, ?int $rateLimitReset): string
    {
        if (($status === 403 || $status === 429) && $rateLimitReset !== null) {
            return 'GitHub API Rate Limit erreicht. Nächster Versuch nach ' . date(DATE_ATOM, $rateLimitReset) . '.';
        }
        if ($status === 403) {
            return 'GitHub API Zugriff verweigert oder Authentifizierung erforderlich.';
        }
        if ($status === 404) {
            return 'GitHub Repository oder Release wurde nicht gefunden.';
        }
        if ($status === 429 || $status >= 500) {
            return 'GitHub API derzeit nicht verfügbar oder Rate Limit erreicht.';
        }
        return sprintf('GitHub API lieferte HTTP %d.', $status);
    }

    private function classifyGithubError(int $status, ?int $rateLimitRemaining): ?string
    {
        if (($status >= 200 && $status < 300) || $status === 304) {
            return null;
        }
        if (($status === 403 || $status === 429) && $rateLimitRemaining === 0) {
            return self::ERROR_RATE_LIMIT;
        }
        if ($status === 403) {
            return self::ERROR_ACCESS_DENIED;
        }
        if ($status === 404) {
            return self::ERROR_NOT_FOUND;
        }
        if ($status === 429 || $status >= 500) {
            return self::ERROR_TEMPORARY_GITHUB_ERROR;
        }

        return null;
    }

    private function detectRepositoryVisibility(mixed $payload): string
    {
        if (!is_array($payload)) {
            return 'unknown';
        }
        foreach ($payload as $release) {
            if (!is_array($release)) {
                continue;
            }
            $url = $release['html_url'] ?? null;
            if (is_string($url) && str_contains($url, 'github.com/')) {
                return 'public';
            }
            $assets = is_array($release['assets'] ?? null) ? $release['assets'] : [];
            foreach ($assets as $asset) {
                if (!is_array($asset)) {
                    continue;
                }
                $downloadUrl = $asset['browser_download_url'] ?? null;
                if (is_string($downloadUrl) && str_starts_with($downloadUrl, 'https://github.com/')) {
                    return 'public';
                }
            }
        }

        return 'unknown';
    }

    private function sanitizeError(string $message): string
    {
        $token = $this->resolveToken();
        if ($token !== '') {
            $message = str_replace($token, '[redacted]', $message);
        }
        return trim($message);
    }

    /** @param array<int, mixed> $releases @return array<int, array<string, mixed>> */
    private function filterReleasesByChannel(array $releases, string $channel): array
    {
        $channel = $this->normalizeChannel($channel);
        $filtered = [];
        foreach ($releases as $release) {
            if (!is_array($release) || ($release['draft'] ?? false) === true) {
                continue;
            }
            if ($this->detectReleaseChannel($release) !== $channel) {
                continue;
            }
            $filtered[] = $release;
        }

        return $filtered;
    }

    private function extractExplicitChannel(array $release): ?string
    {
        foreach (['body', 'name'] as $field) {
            $value = $release[$field] ?? null;
            if (!is_string($value) || trim($value) === '') {
                continue;
            }
            if (preg_match('/(?:^|\R)\s*(?:easywi[-_ ]?)?channel\s*[:=]\s*(stable|beta|dev|alpha)\b/i', $value, $matches) === 1) {
                return $this->normalizeChannel($matches[1]);
            }
        }

        return null;
    }

    /** @param array<string, mixed> $release */
    private function extractReleaseTag(array $release): ?string
    {
        $tag = $release['tag_name'] ?? $release['name'] ?? null;
        if (!is_string($tag)) {
            return null;
        }

        $tag = trim($tag);
        return $tag !== '' ? $tag : null;
    }

    /**
     * @param array<int, mixed> $assets
     * @param callable(string,string):bool|int $assetMatcher
     */
    private function findMatchingAssetName(array $assets, string $releaseTag, callable $assetMatcher): ?string
    {
        $matchedName = null;
        $matchedPriority = PHP_INT_MAX;
        foreach ($assets as $index => $asset) {
            if (!is_array($asset)) {
                continue;
            }

            $name = is_string($asset['name'] ?? null) ? trim($asset['name']) : '';
            if ($name === '') {
                continue;
            }

            $match = $assetMatcher($name, $releaseTag);
            if ($match === false) {
                continue;
            }

            $priority = is_int($match) ? $match : 1000 + $index;
            if ($matchedName === null || $priority < $matchedPriority) {
                $matchedName = $name;
                $matchedPriority = $priority;
            }
        }

        return $matchedName;
    }

    /** @param array<int, mixed> $assets */
    private function findAssetDownloadUrl(array $assets, string $assetName): ?string
    {
        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }

            if (($asset['name'] ?? null) !== $assetName) {
                continue;
            }

            $publicUrl = is_string($asset['browser_download_url'] ?? null) ? trim($asset['browser_download_url']) : '';
            if ($publicUrl !== '') {
                return $publicUrl;
            }

            $apiUrl = is_string($asset['url'] ?? null) ? trim($asset['url']) : '';
            if ($apiUrl !== '' && $this->usesAuthenticatedGithubApi()) {
                return $apiUrl;
            }
        }

        return null;
    }

    private function usesAuthenticatedGithubApi(): bool
    {
        return $this->resolveToken() !== '';
    }
}
