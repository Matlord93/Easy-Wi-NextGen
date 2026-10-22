<?php

declare(strict_types=1);

namespace App\Infrastructure\Asset;

use Symfony\Component\Asset\VersionStrategy\VersionStrategyInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ContentVersionStrategy implements VersionStrategyInterface
{
    private array $versions = [];

    public function __construct(#[Autowire('%kernel.project_dir%/public')] private readonly string $publicDirectory)
    {
    }

    public function getVersion(string $path): string
    {
        if (str_starts_with($path, '//') || null !== parse_url($path, PHP_URL_SCHEME)) {
            return '';
        }
        $path = rawurldecode((string) parse_url($path, PHP_URL_PATH));
        if (isset($this->versions[$path])) {
            return $this->versions[$path];
        }
        $root = realpath($this->publicDirectory);
        $file = realpath($this->publicDirectory.'/'.ltrim($path, '/'));
        if (false === $root || false === $file || !str_starts_with($file, $root.DIRECTORY_SEPARATOR) || !is_file($file)) {
            return '';
        }

        return $this->versions[$path] = substr((string) hash_file('sha256', $file), 0, 16);
    }

    public function applyVersion(string $path): string
    {
        $version = $this->getVersion($path);
        if ('' === $version) {
            return $path;
        }
        $parts = explode('#', $path, 2);
        $base = $parts[0];

        return $base.(str_contains($base, '?') ? '&' : '?').'v='.$version.(isset($parts[1]) ? '#'.$parts[1] : '');
    }
}
