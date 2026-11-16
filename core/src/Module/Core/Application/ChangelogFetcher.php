<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use Psr\Cache\CacheItemPoolInterface;
use App\Infrastructure\Config\JsonArray;
use App\Infrastructure\Config\JsonMap;

final class ChangelogFetcher
{
    private const CACHE_KEY = 'public.changelog.releases';

    private readonly CacheItemPoolInterface $cache;
    private readonly string $repository;
    private readonly int $cacheTtlSeconds;

    public function __construct(
        CacheItemPoolInterface $cache,
        ?string $repository,
        int $cacheTtlSeconds = 3600,
        private readonly ?GithubReleaseResolver $releaseResolver = null,
    ) {
        $this->cache = $cache;
        $this->repository = $repository ?? '';
        $this->cacheTtlSeconds = $cacheTtlSeconds;
    }

    public function getRepository(): string
    {
        return $this->repository;
    }

    /**
     * @return array<int, array{title: string, version: string|null, content: string, published_at:
     * \DateTimeImmutable|null}>
     */
    public function getReleases(): array
    {
        if ($this->repository === '') {
            return [];
        }

        $item = $this->cache->getItem(self::CACHE_KEY . '.' . hash('sha256', $this->repository));
        $cached = self::cachedReleases($item->get());
        if ($item->isHit() && is_array($cached)) {
            return $cached;
        }

        $releases = $this->fetchReleases();
        $item->set($releases);
        $item->expiresAfter($this->cacheTtlSeconds);
        $this->cache->save($item);

        return $releases;
    }

    /**
     * @return array<int, array{title: string, version: string|null, content: string, published_at:
     * \DateTimeImmutable|null}>
     */
    private function fetchReleases(): array
    {
        if ($this->releaseResolver !== null) {
            $payload = $this->releaseResolver->getCachedReleases($this->repository, GithubReleaseResolver::CHANNEL_STABLE, 'changelog');
            if ($payload === null) {
                return [];
            }
        } else {
            $url = sprintf('https://api.github.com/repos/%s/releases?per_page=50', $this->repository);
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
                return [];
            }

            try {
                $payload = JsonArray::fromValue(json_decode($response, true, 512, JSON_THROW_ON_ERROR));
            } catch (\JsonException) {
                return [];
            }
            if (!is_array($payload)) {
                return [];
            }
        }

        $items = [];
        foreach ($payload as $release) {
            if (!is_array($release)) {
                continue;
            }
            $title = $release['name'] ?? $release['tag_name'] ?? null;
            if (!is_string($title) || $title === '') {
                continue;
            }
            $version = self::optionalText($release['tag_name'] ?? null);
            $content = self::optionalText($release['body'] ?? null) ?? '';
            $publishedAt = $this->parsePublishedAt($release['published_at'] ?? null);

            $items[] = [
                'title' => $title,
                'version' => $version,
                'content' => $content,
                'published_at' => $publishedAt,
            ];
        }

        return $items;
    }

    /** @return array<string, mixed> */
    public function getCacheStatus(): array
    {
        if ($this->releaseResolver === null) {
            return ['has_cache' => false, 'ttl' => $this->cacheTtlSeconds];
        }

        return $this->releaseResolver->getCacheStatus($this->repository, GithubReleaseResolver::CHANNEL_STABLE, 'changelog');
    }

    private static function optionalText(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /** @return list<array{title:string,version:?string,content:string,published_at:?\DateTimeImmutable}>|null */
    private static function cachedReleases(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $items = [];
        foreach (array_keys($value) as $key) {
            $row = JsonArray::fromValue($value[$key]);
            if ($row === null || !is_string($row['title'] ?? null) || !is_string($row['content'] ?? null)) {
                return null;
            }

            $date = self::cachedDate($row['published_at'] ?? null);
            if (($row['published_at'] ?? null) !== null && $date === null) {
                return null;
            }

            $items[] = [
                'title' => self::optionalText($row['title']) ?? '',
                'version' => self::optionalText($row['version'] ?? null),
                'content' => self::optionalText($row['content']) ?? '',
                'published_at' => $date,
            ];
        }

        return $items;
    }

    private static function cachedDate(mixed $value): ?\DateTimeImmutable
    {
        return $value instanceof \DateTimeImmutable ? $value : null;
    }

    private function parsePublishedAt(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
