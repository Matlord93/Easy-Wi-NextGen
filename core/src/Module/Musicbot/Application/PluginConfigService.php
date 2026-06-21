<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

use App\Module\Musicbot\Application\Dto\PluginManifest;
use App\Module\Musicbot\Domain\Entity\MusicbotPlugin;

final class PluginConfigService
{
    /** @param array<string, mixed> $config */
    public function saveConfig(MusicbotPlugin $plugin, PluginManifest $manifest, array $config): void
    {
        $plugin->setConfig($this->filterConfig($config, $manifest->configSchema));
    }

    /** @param array<string, mixed> $config @param array<string, mixed> $schema @return array<string, mixed> */
    public function filterConfig(array $config, array $schema): array
    {
        if ($schema === []) {
            return $this->sanitizeScalarConfig($config);
        }
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        if ($properties === []) {
            return $this->sanitizeScalarConfig($config);
        }
        $filtered = [];
        foreach ($properties as $key => $definition) {
            if (!array_key_exists($key, $config)) {
                continue;
            }
            $filtered[$key] = $this->coerceValue($config[$key], is_array($definition) ? (string) ($definition['type'] ?? 'string') : 'string');
        }

        return $filtered;
    }

    /** @param array<string, mixed> $config @return array<string, mixed> */
    private function sanitizeScalarConfig(array $config): array
    {
        $sanitized = [];
        foreach ($config as $key => $value) {
            if (!is_string($key) || str_contains($key, '..') || str_contains($key, '/') || str_contains($key, '\\')) {
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $sanitized[$key] = $value;
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitizeScalarConfig($value);
            }
        }

        return $sanitized;
    }

    private function coerceValue(mixed $value, string $type): mixed
    {
        return match ($type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false,
            'integer' => (int) $value,
            'number' => (float) $value,
            'array' => is_array($value) ? $this->sanitizeScalarConfig($value) : [],
            default => is_scalar($value) || $value === null ? (string) $value : '',
        };
    }
}
