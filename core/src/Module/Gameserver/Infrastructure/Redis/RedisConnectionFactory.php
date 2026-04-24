<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Infrastructure\Redis;

final class RedisConnectionFactory
{
    public static function create(string $dsn): \Redis
    {
        $parts = parse_url($dsn);
        if (!is_array($parts)) {
            throw new \RuntimeException('Invalid REDIS_DSN.');
        }

        $host = (string) ($parts['host'] ?? '127.0.0.1');
        $port = (int) ($parts['port'] ?? 6379);
        $db = isset($parts['path']) ? (int) ltrim((string) $parts['path'], '/') : 0;

        $redis = new \Redis();

        try {
            $redis->connect($host, $port, 1.5);
            if (isset($parts['pass'])) {
                $redis->auth($parts['pass']);
            }
            if ($db > 0) {
                $redis->select($db);
            }
        } catch (\RedisException) {
            // Redis is optional in some panel setups (e.g. Plesk-only deployments).
            // Return a non-connected client so runtime diagnostics can degrade gracefully
            // instead of failing the entire HTTP request at container service creation time.
        }

        return $redis;
    }
}
