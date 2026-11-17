<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup;

use Cron\CronExpression;

/** Explicit, versioned JSON; never accepts executable PHP serialization. */
final readonly class BackupPlanJsonCodec
{
    public function __construct(private BackupTargetValidator $targetValidator)
    {
    }

    public function encode(BackupPlan $plan): string
    {
        $json = json_encode([
            'version' => 1,
            'id' => $plan->id(),
            'module' => $plan->module(),
            'resource_id' => $plan->resourceId(),
            'target' => ['type' => $plan->target()->type(), 'label' => $plan->target()->label(), 'config' => $plan->target()->config(), 'secrets' => $plan->target()->secrets()],
            'retention' => ['keep_count' => $plan->retentionPolicy()->keepCount(), 'keep_days' => $plan->retentionPolicy()->keepDays()],
            'cron' => $plan->cronExpression(),
            'timezone' => $plan->timeZone(),
            'options' => $plan->options(),
        ], JSON_THROW_ON_ERROR);
        $this->decode($json);

        return $json;
    }

    public function decode(string $json): BackupPlan
    {
        $data = self::map(json_decode($json, true, 64, JSON_THROW_ON_ERROR), 'plan');
        self::keys($data, ['version', 'id', 'module', 'resource_id', 'target', 'retention', 'cron', 'timezone', 'options']);
        if (($data['version'] ?? null) !== 1) {
            throw new \InvalidArgumentException('Unsupported backup plan schema version.');
        }
        $target = self::map($data['target'] ?? null, 'target');
        self::keys($target, ['type', 'label', 'config', 'secrets']);
        $storage = new BackupStorageTarget(self::text($target, 'type'), self::text($target, 'label'), self::values($target['config'] ?? []), self::values($target['secrets'] ?? []));
        $this->targetValidator->validate($storage);
        $retention = self::map($data['retention'] ?? null, 'retention');
        self::keys($retention, ['keep_count', 'keep_days']);
        $count = $retention['keep_count'] ?? null;
        $days = $retention['keep_days'] ?? null;
        if (!is_int($count) || $count < 0 || !is_int($days) || $days < 0) {
            throw new \InvalidArgumentException('Retention counts must be nonnegative integers.');
        }
        $cron = $data['cron'] ?? null;
        if (null !== $cron && (!is_string($cron) || !CronExpression::isValidExpression($cron))) {
            throw new \InvalidArgumentException('Invalid backup cron expression.');
        }
        $timezone = self::text($data, 'timezone');
        try {
            new \DateTimeZone($timezone);
        } catch (\Exception $exception) {
            throw new \InvalidArgumentException('Invalid backup timezone.', 0, $exception);
        }
        $options = self::values($data['options'] ?? []);
        if (isset($options['compression']) && !in_array($options['compression'], ['none', 'gzip'], true)) {
            throw new \InvalidArgumentException('Backup compression must be none or gzip.');
        }
        foreach (['encrypt', 'lock_target', 'retention_enabled'] as $flag) {
            if (isset($options[$flag]) && !is_bool($options[$flag])) {
                throw new \InvalidArgumentException('Backup flags must be booleans.');
            }
        }
        foreach (['lock_ttl_seconds', 'idempotency_ttl_seconds'] as $ttl) {
            if (isset($options[$ttl]) && (!is_int($options[$ttl]) || $options[$ttl] < 60)) {
                throw new \InvalidArgumentException('Backup TTL options must be integers of at least 60 seconds.');
            }
        }
        if (($options['encrypt'] ?? false) === true && (!is_string($options['encryption_key'] ?? null) || '' === $options['encryption_key'])) {
            throw new \InvalidArgumentException('Encrypted backups require a nonempty encryption_key.');
        }

        return new BackupPlan(self::text($data, 'id'), self::text($data, 'module'), self::text($data, 'resource_id'), $storage, new RetentionPolicy($count, $days), $cron, $timezone, $options);
    }

    /** @return array<string, mixed> */
    private static function map(mixed $value, string $name): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException('Backup '.$name.' must be an object.');
        }
        $fields = [];
        foreach (array_keys($value) as $key) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException('Backup '.$name.' must have string keys.');
            }
            $fields += [$key => $value[$key]];
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $allowed
     */
    private static function keys(array $data, array $allowed): void
    {
        if ([] !== array_diff(array_keys($data), $allowed)) {
            throw new \InvalidArgumentException('Unknown backup plan field.');
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return non-empty-string
     */
    private static function text(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || '' === $value || '' === trim($value) || strlen($value) > 4096 || str_contains($value, "\0")) {
            throw new \InvalidArgumentException('Invalid backup '.$key.' field.');
        }

        return $value;
    }

    /** @return array<string, scalar|array|null> */
    private static function values(mixed $value): array
    {
        $result = [];
        foreach (self::map($value, 'options') as $key => $item) {
            if (null !== $item && !is_scalar($item) && !is_array($item)) {
                throw new \InvalidArgumentException('Invalid backup configuration value.');
            }
            $result[$key] = $item;
        }

        return $result;
    }
}
