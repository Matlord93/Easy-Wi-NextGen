<?php

declare(strict_types=1);

namespace App\Module\Nodes\Application\Monitoring;

use App\Infrastructure\Config\JsonArray;
use App\Infrastructure\Config\JsonMap;
use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Application\AppSettingsServiceInterface;

/**
 * @psalm-type MonitoringOptions = array{enabled: bool, retention_days: int, proxmox_batch_size: int,
 *     alerts_enabled: bool, hold_seconds: int, offline_seconds: int,
 *     cpu_percent: int, memory_percent: int, disk_percent: int}
 * @psalm-type CollectorResult = array{samples: int, unavailable: int, pruned: int, rollups_backfilled: int, skipped: bool}
 * @psalm-type CollectorState = array{vm_cursors: array<int, int>, known_nodes: array<int, list<string>>,
 *     cluster_cursor: int, rollups_initialized: bool, last_minute?: int, last_run_at?: string,
 *     last_result?: CollectorResult}
 */
final readonly class InfrastructureMonitoringSettings
{
    public function __construct(private AppSettingsServiceInterface $settings)
    {
    }

    /** @return MonitoringOptions */
    public function get(): array
    {
        $stored = JsonArray::orEmpty($this->settings->getSettings()[AppSettingsService::KEY_INFRA_MONITORING] ?? null);

        return self::options($stored, false);
    }

    /** @param array<array-key, mixed> $input */
    public function save(array $input): void
    {
        $this->settings->updateSettings([AppSettingsService::KEY_INFRA_MONITORING => self::options($input, true)]);
    }

    /** @return CollectorState */
    public function state(): array
    {
        $raw = JsonArray::orEmpty($this->settings->getSettings()[AppSettingsService::KEY_INFRA_MONITORING_STATE] ?? null);
        $rawCursors = JsonArray::orEmpty($raw['vm_cursors'] ?? null);
        $rawKnownNodes = JsonArray::orEmpty($raw['known_nodes'] ?? null);
        $cursors = [];
        foreach (array_keys($rawCursors) as $key) {
            $value = self::stateInteger($rawCursors[$key]);
            if (is_int($key) && $key >= 0 && $value !== null) {
                $cursors[$key] = $value;
            }
        }
        $knownNodes = [];
        foreach (array_keys($rawKnownNodes) as $key) {
            if (is_int($key) && $key >= 0) {
                $knownNodes[$key] = array_values(array_filter(
                    JsonArray::orEmpty($rawKnownNodes[$key]),
                    static fn (mixed $name): bool => is_string($name) && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,62}$/D', $name) === 1,
                ));
            }
        }
        $state = [
            'vm_cursors' => $cursors,
            'known_nodes' => $knownNodes,
            'cluster_cursor' => self::stateInteger($raw['cluster_cursor'] ?? null) ?? 0,
            'rollups_initialized' => filter_var($raw['rollups_initialized'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
        $minute = self::stateInteger($raw['last_minute'] ?? null);
        if ($minute !== null) {
            $state['last_minute'] = $minute;
        }
        $strings = JsonMap::strings($raw);
        if (isset($strings['last_run_at'])) {
            $state['last_run_at'] = $strings['last_run_at'];
        }
        $lastResult = JsonArray::fromValue($raw['last_result'] ?? null);
        if ($lastResult !== null) {
            $state['last_result'] = [
                'samples' => self::stateInteger($lastResult['samples'] ?? null) ?? 0,
                'unavailable' => self::stateInteger($lastResult['unavailable'] ?? null) ?? 0,
                'pruned' => self::stateInteger($lastResult['pruned'] ?? null) ?? 0,
                'rollups_backfilled' => self::stateInteger($lastResult['rollups_backfilled'] ?? null) ?? 0,
                'skipped' => filter_var($lastResult['skipped'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ];
        }

        return $state;
    }

    /** @param CollectorState $state */
    public function updateState(array $state): void
    {
        $this->settings->updateSettings([AppSettingsService::KEY_INFRA_MONITORING_STATE => $state]);
    }

    /**
     * @param array<array-key, mixed> $input
     * @return MonitoringOptions
     */
    private static function options(array $input, bool $strict): array
    {
        return [
            'enabled' => self::boolean($input['enabled'] ?? ($strict ? false : true), !$strict),
            'retention_days' => self::bounded($input['retention_days'] ?? null, 1, 365, 30, $strict),
            'proxmox_batch_size' => self::bounded($input['proxmox_batch_size'] ?? null, 1, 256, 32, $strict),
            'alerts_enabled' => self::boolean($input['alerts_enabled'] ?? ($strict ? false : true), !$strict),
            'hold_seconds' => self::bounded($input['hold_seconds'] ?? null, 60, 3600, 180, $strict),
            'offline_seconds' => self::bounded($input['offline_seconds'] ?? null, 120, 3600, 180, $strict),
            'cpu_percent' => self::bounded($input['cpu_percent'] ?? null, 1, 100, 90, $strict),
            'memory_percent' => self::bounded($input['memory_percent'] ?? null, 1, 100, 90, $strict),
            'disk_percent' => self::bounded($input['disk_percent'] ?? null, 1, 100, 90, $strict),
        ];
    }

    private static function boolean(mixed $value, bool $default): bool
    {
        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $parsed ?? $default;
    }

    private static function bounded(mixed $value, int $min, int $max, int $default, bool $strict): int
    {
        $parsed = filter_var($value, FILTER_VALIDATE_INT);
        if (!is_int($parsed) || $parsed < $min || $parsed > $max) {
            if ($strict) {
                throw new \InvalidArgumentException('monitor.invalid_settings');
            }
            return $default;
        }

        return $parsed;
    }

    private static function stateInteger(mixed $value): ?int
    {
        $parsed = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($parsed) && $parsed >= 0 ? $parsed : null;
    }
}
