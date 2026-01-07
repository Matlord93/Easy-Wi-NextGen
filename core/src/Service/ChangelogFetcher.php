<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Cache\CacheItemPoolInterface;

final class ChangelogFetcher
{
    private const CACHE_KEY = 'public.changelog.releases';

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly string $repository,
        private readonly int $cacheTtlSeconds = 300,
    ) {
    }

    public function getRepository(): string
    {
        return $this->repository;
    }

    /**
     * @return array<int, array{title: string, version: string|null, content: string, published_at: \DateTimeImmutable|null}>
     */
    public function getReleases(): array
    {
        if ($this->repository === '') {
            return [];
        }

        $item = $this->cache->getItem(self::CACHE_KEY . '.' . md5($this->repository));
        $cached = $item->get();
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
     * @return array<int, array{title: string, version: string|null, content: string, published_at: \DateTimeImmutable|null}>
     */
    private function fetchReleases(): array
    {
        $url = sprintf('https://api.github.com/repos/%s/releases', $this->repository);
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
            $payload = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!is_array($payload)) {
            return [];
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
            $version = is_string($release['tag_name'] ?? null) ? $release['tag_name'] : null;
            $content = is_string($release['body'] ?? null) ? $release['body'] : '';
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
