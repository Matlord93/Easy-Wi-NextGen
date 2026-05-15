<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class GithubReleaseResolver
{
    public const CHANNEL_STABLE = 'stable';
    public const CHANNEL_BETA = 'beta';
    public const CHANNEL_DEV = 'dev';
    public const CHANNEL_ALPHA = 'alpha';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ?string $token = null,
    ) {
    }

    public function getLatestVersion(string $repository, string $channel): ?string
    {
        $releases = $this->fetchReleases($repository);
        if ($releases === null) {
            return null;
        }

        $selected = $this->selectLatestRelease($releases, $channel);
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
    ): ?array {
        $releases = $this->fetchReleases($repository);
        if ($releases === null) {
            return null;
        }

        return $this->selectLatestAsset($releases, $channel, $assetName, $checksumsAssetName, $signatureAssetName, $targetVersion);
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
            $downloadUrl = $this->findAssetDownloadUrl($assets, $assetName);
            $checksumsUrl = $this->findAssetDownloadUrl($assets, $checksumsAssetName);
            if ($downloadUrl === null || $checksumsUrl === null) {
                continue;
            }

            $candidate = [
                'version' => $tag,
                'download_url' => $downloadUrl,
                'checksums_url' => $checksumsUrl,
                'signature_url' => $signatureAssetName !== null ? $this->findAssetDownloadUrl($assets, $signatureAssetName) : null,
                'asset_name' => $assetName,
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
            return version_compare($leftNormalized, $rightNormalized);
        }

        return strcmp($leftTag, $rightTag);
    }

    /** @return array<int, mixed>|null */
    private function fetchReleases(string $repository): ?array
    {
        $repository = trim($repository, '/ ');
        if ($repository === '' || preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $repository) !== 1) {
            return null;
        }

        $headers = [
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => 'Easy-Wi-NextGen',
            'X-GitHub-Api-Version' => '2022-11-28',
        ];
        $token = $this->token !== null ? trim($this->token) : '';
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        try {
            $response = $this->httpClient->request('GET', sprintf('https://api.github.com/repos/%s/releases?per_page=50', $repository), [
                'headers' => $headers,
                'timeout' => 10,
            ]);
            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                return null;
            }

            $payload = json_decode($response->getContent(false), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        return is_array($payload) ? $payload : null;
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

            $url = '';
            if ($this->usesAuthenticatedGithubApi()) {
                $url = is_string($asset['url'] ?? null) ? trim($asset['url']) : '';
            }
            if ($url === '') {
                $url = is_string($asset['browser_download_url'] ?? null) ? trim($asset['browser_download_url']) : '';
            }
            if ($url !== '') {
                return $url;
            }
        }

        return null;
    }

    private function usesAuthenticatedGithubApi(): bool
    {
        return $this->token !== null && trim($this->token) !== '';
    }
}
