<?php

declare(strict_types=1);

namespace App\Module\Teamspeak\Application\Update;

final class TeamspeakUpdateLockManager
{
    public function __construct(private readonly string $lockDir) {}

    public function acquire(string $instanceType, int $instanceId, int $ttlSeconds = 3600): bool
    {
        if (!is_dir($this->lockDir)) { @mkdir($this->lockDir, 0770, true); }
        $path = $this->lockPath($instanceType, $instanceId);
        $now = time();
        if (is_file($path)) {
            $content = json_decode((string) file_get_contents($path), true);
            $expires = (int) ($content['expires_at'] ?? 0);
            if ($expires > $now) { return false; }
        }
        return false !== file_put_contents($path, json_encode(['expires_at' => $now + $ttlSeconds, 'created_at' => $now]), LOCK_EX);
    }

    public function release(string $instanceType, int $instanceId): void
    {
        $path = $this->lockPath($instanceType, $instanceId);
        if (is_file($path)) { @unlink($path); }
    }

    private function lockPath(string $instanceType, int $instanceId): string
    {
        return rtrim($this->lockDir, '/').sprintf('/%s-%d.lock', preg_replace('/[^a-z0-9_\-]/i', '', $instanceType), $instanceId);
    }
}
