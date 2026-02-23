<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application;

use App\Module\Core\Application\InstanceFilesystemResolver;
use App\Module\Core\Domain\Entity\Instance;

final class InstanceConfigPathResolver
{
    public function __construct(
        private readonly InstanceFilesystemResolver $filesystemResolver,
    ) {
    }

    /**
     * @return array{root:string,relative:string,absolute:string,os:string,source_rule:string}
     */
    public function resolve(Instance $instance, string $relativePath): array
    {
        $root = trim((string) $instance->getInstallPath());
        if ($root === '') {
            $root = $this->filesystemResolver->resolveInstanceDir($instance);
        }

        $os = $this->resolveOs($instance);
        $relativePath = trim(str_replace('\\', '/', $relativePath), '/ ');
        if ($relativePath === '' || str_contains($relativePath, '..')) {
            throw new \RuntimeException('PATH_TRAVERSAL');
        }

        $rootNormalized = $this->normalizeAbsolute($root, $os);
        $absolute = $this->normalizeAbsolute($rootNormalized . '/' . $relativePath, $os);

        if (!$this->isWithinRoot($absolute, $rootNormalized, $os)) {
            throw new \RuntimeException('PATH_TRAVERSAL');
        }

        return [
            'root' => $rootNormalized,
            'relative' => $relativePath,
            'absolute' => $absolute,
            'os' => $os,
            'source_rule' => 'instance_root_plus_relative',
        ];
    }

    private function resolveOs(Instance $instance): string
    {
        $metadata = $instance->getNode()->getMetadata() ?? [];
        $heartbeat = $instance->getNode()->getLastHeartbeatStats() ?? [];
        $os = strtolower(trim((string) ($metadata['os'] ?? $heartbeat['os'] ?? 'linux')));

        return str_contains($os, 'win') ? 'windows' : 'linux';
    }

    private function normalizeAbsolute(string $path, string $os): string
    {
        $path = str_replace('\\', '/', trim($path));
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        if ($os === 'windows') {
            if (preg_match('/^[a-zA-Z]:$/', $parts[0] ?? '') === 1) {
                $drive = array_shift($parts);
                return $drive . '/' . implode('/', $parts);
            }

            return implode('/', $parts);
        }

        return '/' . implode('/', $parts);
    }

    private function isWithinRoot(string $absolute, string $root, string $os): bool
    {
        $a = $os === 'windows' ? strtolower($absolute) : $absolute;
        $r = $os === 'windows' ? strtolower(rtrim($root, '/')) : rtrim($root, '/');

        return $a === $r || str_starts_with($a, $r . '/');
    }
}
