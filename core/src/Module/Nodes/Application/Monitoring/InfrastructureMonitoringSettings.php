<?php

declare(strict_types=1);

namespace App\Module\Nodes\Application\Monitoring;

use App\Module\Core\Application\AppSettingsService;

final readonly class InfrastructureMonitoringSettings
{
    public function __construct(private AppSettingsService $settings)
    {
    }

    public function get(): array
    {
        $stored = $this->settings->getSettings()[AppSettingsService::KEY_INFRA_MONITORING] ?? [];

        return array_replace(['enabled' => true, 'retention_days' => 30, 'proxmox_batch_size' => 32, 'alerts_enabled' => true, 'hold_seconds' => 180, 'offline_seconds' => 180, 'cpu_percent' => 90, 'memory_percent' => 90, 'disk_percent' => 90], is_array($stored) ? $stored : []);
    }

    public function save(array $input): void
    {
        $values = [];
        foreach (['retention_days' => [1, 365], 'proxmox_batch_size' => [1, 256], 'hold_seconds' => [60, 3600], 'offline_seconds' => [120, 3600], 'cpu_percent' => [1, 100], 'memory_percent' => [1, 100], 'disk_percent' => [1, 100]] as $field => [$min, $max]) {
            $value = filter_var($input[$field] ?? null, FILTER_VALIDATE_INT);
            if (false === $value || $value < $min || $value > $max) {
                throw new \InvalidArgumentException('monitor.invalid_settings');
            }
            $values[$field] = $value;
        }
        foreach (['enabled', 'alerts_enabled'] as $field) {
            $values[$field] = filter_var($input[$field] ?? false, FILTER_VALIDATE_BOOLEAN);
        }
        $this->settings->updateSettings([AppSettingsService::KEY_INFRA_MONITORING => $values]);
    }

    public function state(): array
    {
        $state = $this->settings->getSettings()[AppSettingsService::KEY_INFRA_MONITORING_STATE] ?? [];

        return is_array($state) ? $state : [];
    }

    public function updateState(array $state): void
    {
        $this->settings->updateSettings([AppSettingsService::KEY_INFRA_MONITORING_STATE => $state]);
    }
}
