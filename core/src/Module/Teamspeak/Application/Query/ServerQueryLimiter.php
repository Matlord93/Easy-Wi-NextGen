<?php

declare(strict_types=1);

namespace App\Module\Teamspeak\Application\Query;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class ServerQueryLimiter implements ServerQueryLimiterInterface
{
    private const CACHE_PREFIX = 'ts_query_rate_limit_';

    public function __construct(private readonly CacheInterface $cache)
    {
    }

    public function allow(string $cacheKey, int $baseDelaySeconds, int $maxDelaySeconds): ServerQueryLimiterResult
    {
        $now = time();
        $rateKey = self::CACHE_PREFIX . $cacheKey;

        $state = $this->cache->get($rateKey, function (ItemInterface $item) use ($baseDelaySeconds, $maxDelaySeconds): array {
            $item->expiresAfter(max($maxDelaySeconds * 2, $baseDelaySeconds));
            return [
                'next_allowed_at' => 0,
                'delay' => $baseDelaySeconds,
            ];
        });

        if (is_array($state)) {
            $nextAllowedAt = (int) ($state['next_allowed_at'] ?? 0);
            $delay = (int) ($state['delay'] ?? $baseDelaySeconds);
        } else {
            $nextAllowedAt = 0;
            $delay = $baseDelaySeconds;
        }

        if ($now < $nextAllowedAt) {
            return new ServerQueryLimiterResult(false, max(1, $nextAllowedAt - $now));
        }

        $delay = max($baseDelaySeconds, $delay);
        $nextDelay = min($maxDelaySeconds, $delay * 2);
        $nextAllowedAt = $now + $nextDelay;

        $this->cache->delete($rateKey);
        $this->cache->get($rateKey, function (ItemInterface $item) use ($nextAllowedAt, $nextDelay, $baseDelaySeconds, $maxDelaySeconds): array {
            $item->expiresAfter(max($maxDelaySeconds * 2, $baseDelaySeconds));
            return [
                'next_allowed_at' => $nextAllowedAt,
                'delay' => $nextDelay,
            ];
        });

        return new ServerQueryLimiterResult(true, $nextDelay);
    }

    public function reset(string $cacheKey): void
    {
        $this->cache->delete(self::CACHE_PREFIX . $cacheKey);
    }
}
