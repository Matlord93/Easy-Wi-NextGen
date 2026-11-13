<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application;

use App\Infrastructure\Filesystem\ConfinedLocalPath;

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
        $relativePath = trim(str_replace('\\', '/', $relativePath));
        if ($relativePath === '' || $this->isAbsolutePath($relativePath, $os) || str_contains($relativePath, ':') || preg_match('/[\x00-\x1f\x7f]/', $relativePath.$root)) {
            throw new \RuntimeException('PATH_TRAVERSAL');
        }
        $plainRoot = str_replace('\\', '/', $root);
        if (str_starts_with($plainRoot, '//') || ('windows' === $os ? 1 !== preg_match('#^[A-Za-z]:/[^:]*$#D', $plainRoot) : (!str_starts_with($plainRoot, '/') || str_contains($plainRoot, ':')))) {
            throw new \RuntimeException('ROOT_INVALID');
        }
        $relativePath = trim($relativePath, '/');
        foreach (explode('/', $relativePath) as $segment) {
            if ($segment === '..') {
                throw new \RuntimeException('PATH_TRAVERSAL');
            }
        }

        $rootNormalized = $this->normalizeAbsolute($root, $os);
        $absolute = $this->normalizeAbsolute($rootNormalized . '/' . $relativePath, $os);

        if (!$this->isWithinRoot($absolute, $rootNormalized, $os)) {
            throw new \RuntimeException('PATH_TRAVERSAL');
        }
        if (('windows' === $os) === ('\\' === DIRECTORY_SEPARATOR) && null === ConfinedLocalPath::resolve($rootNormalized, $relativePath)) {
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

    /** @return array{exists: bool, size: ?int} */
    public function stat(Instance $instance, string $relative): array
    {
        $resolved = $this->resolve($instance, $relative);
        $path = ConfinedLocalPath::resolve($resolved['root'], $resolved['relative'], true);
        $exists = null !== $path && is_file($path);
        $size = $exists ? filesize($path) : false;

        return ['exists' => $exists, 'size' => false === $size ? null : $size];
    }

    public function read(Instance $instance, string $relative): ?string
    {
        $resolved = $this->resolve($instance, $relative);
        $path = ConfinedLocalPath::resolve($resolved['root'], $resolved['relative'], true);
        if (null === $path || !is_file($path)) {
            return null;
        }
        $stream = @fopen($path, 'rb');
        if (false === $stream) {
            throw new \RuntimeException('CONFIG_NOT_READABLE');
        }
        try {
            $content = stream_get_contents($stream, 256 * 1024 + 1);
            if (false === $content || strlen($content) > 256 * 1024) {
                throw new \RuntimeException('CONFIG_TOO_LARGE');
            }

            return $content;
        } finally {
            fclose($stream);
        }
    }

    private function resolveOs(Instance $instance): string
    {
        $metadata = $instance->getNode()->getMetadata() ?? [];
        $heartbeat = $instance->getNode()->getLastHeartbeatStats() ?? [];
        $os = strtolower(trim((string) ($metadata['os'] ?? $heartbeat['os'] ?? 'linux')));

        return str_contains($os, 'win') ? 'windows' : 'linux';
    }

    private function isAbsolutePath(string $path, string $os): bool
    {
        $normalized = str_replace('\\', '/', trim($path));
        if ($os === 'windows') {
            return preg_match('/^[a-zA-Z]:\//', $normalized) === 1 || str_starts_with($normalized, '//');
        }

        return str_starts_with($normalized, '/');
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
