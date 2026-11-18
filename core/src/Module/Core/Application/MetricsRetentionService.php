<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Nodes\Application\Monitoring\InfrastructureHistoryStore;
use App\Module\Nodes\Application\Monitoring\InfrastructureMonitoringSettings;
use Symfony\Component\Lock\LockFactory;

final readonly class MetricsRetentionService
{
    public function __construct(private AppSettingsServiceInterface $settings, private RetentionBatchDelete $delete, private InfrastructureHistoryStore $infrastructure, private InfrastructureMonitoringSettings $monitoring, private LockFactory $locks, private \App\Repository\InstanceMetricSampleRepository $instances) {}

    /** @return array<string,int> */
    public function cleanup(?\DateTimeImmutable $now = null, ?int $overrideDays = null, int $batch = 1000): array
    {
        $now = ($now ?? new \DateTimeImmutable())->setTimezone(new \DateTimeZone('UTC'));
        $lock = $this->locks->createLock('core.metrics.retention', 300);
        if (!$lock->acquire()) {
            return [];
        }
        try {
            $settings = $this->settings->getSettings();
            $nodeDays = $overrideDays ?? self::days($settings[AppSettingsService::KEY_METRICS_RETENTION_DAYS] ?? null, 30);
            $instanceDays = $overrideDays ?? self::days($settings[AppSettingsService::KEY_METRICS_INSTANCE_RETENTION_DAYS] ?? null, 1);
            $infraDays = $overrideDays ?? $this->monitoring->get()['retention_days'];
            return [
                'metric_samples_deleted' => $this->delete->olderThan('metric_samples', 'recorded_at', $now->modify('-'.$nodeDays.' days')->format('Y-m-d H:i:s'), $batch),
                'instance_metric_samples_deleted' => $this->instances->deleteOlderThan($now->modify('-'.$instanceDays.' days'), $batch),
                'metric_aggregates_deleted' => $this->delete->olderThan('metric_aggregates', 'bucket_start', $now->modify('-'.$nodeDays.' days')->format('Y-m-d H:i:s'), $batch),
                'infrastructure_rows_deleted' => $this->infrastructure->prune($infraDays, $now),
            ];
        } finally {
            $lock->release();
        }
    }

    private static function days(mixed $value, int $default): int
    {
        $days = filter_var($value, FILTER_VALIDATE_INT);
        return is_int($days) && $days >= 1 ? $days : $default;
    }
}
