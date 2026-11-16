<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

use App\Infrastructure\Config\JsonMap;

use App\Infrastructure\Config\JsonArray;
use App\Module\Musicbot\Application\Dto\PluginManifest;
use App\Module\Musicbot\Domain\Entity\MusicbotPlugin;

final class PluginConfigService
{
    /** @param array<string, mixed> $config */
    public function saveConfig(MusicbotPlugin $plugin, PluginManifest $manifest, array $config): void
    {
        $plugin->setConfig($this->filterConfig($config, $manifest->configSchema));
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public function filterConfig(array $config, array $schema): array
    {
        if ($schema === []) {
            return $this->sanitizeScalarConfig($config);
        }
        $properties = JsonMap::fromValue($schema['properties'] ?? null) ?? [];
        if ($properties === []) {
            return $this->sanitizeScalarConfig($config);
        }
        $filtered = [];
        foreach (array_keys($properties) as $key) {
            if (!array_key_exists($key, $config)) {
                continue;
            }
            $filtered[$key] = $this->coerceValue($config[$key], JsonMap::strings($properties[$key])['type'] ?? 'string');
        }

        return $filtered;
    }

    /**
     * @template TKey of array-key
     * @param array<TKey, mixed> $config
     * @return array<TKey, null|bool|int|float|string|array<array-key, mixed>>
     */
    private function sanitizeScalarConfig(array $config): array
    {
        $sanitized = [];
        foreach (array_keys($config) as $key) {
            if (is_string($key) && (str_contains($key, '..') || str_contains($key, '/') || str_contains($key, '\\'))) {
                continue;
            }
            if (!is_scalar($config[$key]) && !is_array($config[$key]) && $config[$key] !== null) {
                continue;
            }
            $value = MusicbotInputValue::from($config[$key]);
            $sanitized[$key] = is_array($value) ? $this->sanitizeScalarConfig($value) : $value;
        }

        return $sanitized;
    }

    /** @return bool|int|float|string|array<array-key, null|bool|int|float|string|array<array-key, mixed>> */
    private function coerceValue(mixed $value, string $type): bool|int|float|string|array
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
