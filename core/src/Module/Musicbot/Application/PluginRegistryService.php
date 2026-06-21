<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

use App\Module\Musicbot\Application\Dto\PluginManifest;
use App\Module\Musicbot\Domain\Enum\MusicbotPluginPermission;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class PluginRegistryService
{
    private const IDENTIFIER_PATTERN = '/^[a-z0-9][a-z0-9._-]{2,119}$/';

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /** @return PluginManifest[] */
    public function listManifests(): array
    {
        $manifests = [];
        foreach ($this->manifestPaths() as $path) {
            try {
                $manifest = $this->loadManifest($path);
            } catch (\InvalidArgumentException) {
                continue;
            }
            $manifests[$manifest->identifier] = $manifest;
        }

        ksort($manifests);

        return array_values($manifests);
    }

    public function findManifest(string $identifier): ?PluginManifest
    {
        $this->assertValidIdentifier($identifier);
        foreach ($this->listManifests() as $manifest) {
            if ($manifest->identifier === $identifier) {
                return $manifest;
            }
        }

        return null;
    }

    public function loadManifest(string $path): PluginManifest
    {
        $realPath = realpath($path);
        if ($realPath === false || !str_ends_with($realPath, DIRECTORY_SEPARATOR.'manifest.json')) {
            throw new \InvalidArgumentException('Invalid plugin manifest path.');
        }
        $allowedRoots = array_filter(array_map('realpath', $this->pluginRoots()));
        $isAllowed = false;
        foreach ($allowedRoots as $root) {
            if (str_starts_with($realPath, $root.DIRECTORY_SEPARATOR)) {
                $isAllowed = true;
                break;
            }
        }
        if (!$isAllowed) {
            throw new \InvalidArgumentException('Plugin manifest path is outside the plugin registry.');
        }

        $data = json_decode((string) file_get_contents($realPath), true);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Plugin manifest must be JSON.');
        }

        return $this->manifestFromArray($data);
    }

    /** @param array<string, mixed> $data */
    public function manifestFromArray(array $data): PluginManifest
    {
        $identifier = strtolower(trim((string) ($data['identifier'] ?? '')));
        $this->assertValidIdentifier($identifier);
        $permissions = $this->validateStringList($data['permissions'] ?? [], MusicbotPluginPermission::values(), 'permission');
        $platforms = $this->validateStringList($data['supported_platforms'] ?? [], ['teamspeak', 'discord'], 'platform');

        return new PluginManifest(
            $identifier,
            trim((string) ($data['name'] ?? $identifier)),
            trim((string) ($data['version'] ?? '0.0.0')),
            trim((string) ($data['author'] ?? '')),
            trim((string) ($data['description'] ?? '')),
            $permissions,
            $platforms,
            is_array($data['config_schema'] ?? null) ? $data['config_schema'] : [],
            is_array($data['panel_extensions'] ?? null) ? $data['panel_extensions'] : [],
        );
    }

    public function assertValidIdentifier(string $identifier): void
    {
        if (!preg_match(self::IDENTIFIER_PATTERN, $identifier) || str_contains($identifier, '..') || str_contains($identifier, '/') || str_contains($identifier, '\\')) {
            throw new \InvalidArgumentException('Invalid plugin identifier.');
        }
    }

    /** @return string[] */
    private function manifestPaths(): array
    {
        $paths = [];
        foreach ($this->pluginRoots() as $root) {
            if (!is_dir($root)) {
                continue;
            }
            foreach (glob($root.'/*/manifest.json') ?: [] as $path) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /** @return string[] */
    private function pluginRoots(): array
    {
        return [
            $this->projectDir.'/musicbot/plugins',
            $this->projectDir.'/var/musicbot/plugins',
        ];
    }

    /** @param mixed $value @param string[] $allowed @return string[] */
    private function validateStringList(mixed $value, array $allowed, string $label): array
    {
        if (!is_array($value)) {
            return [];
        }
        $result = [];
        foreach ($value as $item) {
            $item = strtolower(trim((string) $item));
            if ($item === '') {
                continue;
            }
            if (!in_array($item, $allowed, true)) {
                throw new \InvalidArgumentException(sprintf('Unsupported plugin %s "%s".', $label, $item));
            }
            $result[] = $item;
        }

        return array_values(array_unique($result));
    }
}
