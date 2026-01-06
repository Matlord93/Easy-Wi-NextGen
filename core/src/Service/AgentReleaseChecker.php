<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Cache\CacheItemPoolInterface;

final class AgentReleaseChecker
{
    private const CACHE_KEY = 'agent.latest_release_version';

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

    public function getLatestVersion(): ?string
    {
        if ($this->repository === '') {
            return null;
        }

        $item = $this->cache->getItem(self::CACHE_KEY);
        $cached = $item->get();
        if ($item->isHit() && is_string($cached) && $cached !== '') {
            return $cached;
        }

        $latest = $this->fetchLatestVersion();
        if ($latest !== null) {
            $item->set($latest);
            $item->expiresAfter($this->cacheTtlSeconds);
            $this->cache->save($item);
        }

        return $latest;
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

    private function fetchLatestVersion(): ?string
    {
        $url = sprintf('https://api.github.com/repos/%s/releases/latest', $this->repository);
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

        $tag = $payload['tag_name'] ?? $payload['name'] ?? null;
        if (!is_string($tag) || $tag === '') {
            return null;
        }

        return $tag;
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
}
