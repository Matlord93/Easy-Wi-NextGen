<?php

declare(strict_types=1);

namespace App\Module\Nodes\Application\Monitoring;

use App\Module\Core\Application\NotificationService;
use App\Infrastructure\Config\JsonArray;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @psalm-import-type MetricStates from InfrastructureAlertEvaluator
 * @psalm-import-type MonitoringOptions from InfrastructureMonitoringSettings
 */
final readonly class InfrastructureAlerts
{
    public function __construct(private Connection $db, private InfrastructureMonitoringSettings $settings, private NotificationService $notifications, private EntityManagerInterface $em)
    {
    }

    /** @return array{thresholds: array<array-key, mixed>, state: MetricStates} */
    public function policy(InfrastructureTarget $target): array
    {
        $row = $this->db->fetchAssociative('SELECT thresholds, state FROM infrastructure_monitor_policies WHERE resource_key = ?', [$target->key]);

        return [
            'thresholds' => $row !== false ? self::jsonObject($row['thresholds'] ?? null) : [],
            'state' => $row !== false ? InfrastructureAlertEvaluator::normalizeState(self::jsonObject($row['state'] ?? null)) : [],
        ];
    }

    public function configure(InfrastructureTarget $target, array $input): void
    {
        $thresholds = [];
        foreach (['cpu_percent', 'memory_percent', 'disk_percent'] as $field) {
            $value = filter_var($input[$field] ?? null, FILTER_VALIDATE_INT);
            if (false === $value || $value < 1 || $value > 100) {
                throw new \InvalidArgumentException('monitor.invalid_settings');
            }
            $thresholds[$field] = $value;
        }
        $thresholds['alerts_enabled'] = filter_var($input['alerts_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->ensure($target);
        $this->db->executeStatement('UPDATE infrastructure_monitor_policies SET thresholds = ?, state = ?, revision = revision + 1 WHERE resource_key = ?', [json_encode($thresholds, JSON_THROW_ON_ERROR), '{}', $target->key]);
    }

    public function observe(InfrastructureTarget $target, array $values, int $observed, bool $available): void
    {
        $this->ensure($target);
        $row = $this->db->fetchAssociative('SELECT thresholds, state, revision FROM infrastructure_monitor_policies WHERE resource_key = ?', [$target->key]);
        if ($row === false) {
            throw new \UnexpectedValueException('Monitoring policy disappeared while collecting.');
        }
        $global = $this->settings->get();
        $thresholds = self::thresholds($global, self::jsonObject($row['thresholds'] ?? null));
        if (!$global['alerts_enabled'] || !$thresholds['alerts_enabled']) {
            return;
        }
        $result = InfrastructureAlertEvaluator::evaluate(self::jsonObject($row['state'] ?? null), $values, $thresholds, $observed, $available);
        $this->db->beginTransaction();
        try {
            $changed = $this->db->executeStatement('UPDATE infrastructure_monitor_policies SET state = ?, revision = revision + 1 WHERE resource_key = ? AND revision = ?', [json_encode($result['state'], JSON_THROW_ON_ERROR), $target->key, $row['revision']]);
            if ((int) $changed !== 0) {
                foreach ($result['events'] as $event) {
                    $key = 'infra:'.$target->key.':'.$event['metric'].':'.$event['episode'].':'.(int) $event['active'];
                    $title = ($event['active'] ? 'Monitoring-Alarm: ' : 'Monitoring wieder normal: ').$target->label;
                    $body = $event['metric'].' · '.gmdate('Y-m-d H:i:s', $observed).' UTC';
                    $this->notifications->notifyAdmins($key, mb_substr($title, 0, 160), $body, 'infrastructure', $target->adminUrl);
                    if ($target->customer !== null && $target->customerUrl !== null && $target->customerUrl !== '') {
                        $this->notifications->notify($target->customer, $key, mb_substr($title, 0, 160), $body, 'infrastructure', $target->customerUrl);
                    }
                }
                $this->em->flush();
            }
            $this->db->commit();
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }

    /** @return array<array-key, mixed> */
    private static function jsonObject(mixed $value): array
    {
        if (!is_string($value)) {
            throw new \UnexpectedValueException('Invalid monitoring policy JSON.');
        }
        return JsonArray::fromValue(json_decode($value, true, 32, JSON_THROW_ON_ERROR))
            ?? throw new \UnexpectedValueException('Invalid monitoring policy object.');
    }

    /**
     * @param MonitoringOptions $global
     * @param array<array-key, mixed> $overrides
     * @return MonitoringOptions
     */
    private static function thresholds(array $global, array $overrides): array
    {
        foreach (['cpu_percent', 'memory_percent', 'disk_percent'] as $field) {
            $value = filter_var($overrides[$field] ?? null, FILTER_VALIDATE_INT);
            if (is_int($value) && $value >= 1 && $value <= 100) {
                $global[$field] = $value;
            }
        }
        if (array_key_exists('alerts_enabled', $overrides)) {
            $enabled = filter_var($overrides['alerts_enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($enabled !== null) {
                $global['alerts_enabled'] = $enabled;
            }
        }
        return $global;
    }

    private function ensure(InfrastructureTarget $target): void
    {
        if ($this->db->fetchOne('SELECT resource_key FROM infrastructure_monitor_policies WHERE resource_key = ?', [$target->key]) !== false) {
            return;
        }
        try {
            $this->db->insert('infrastructure_monitor_policies', ['resource_key' => $target->key, 'thresholds' => '{}', 'state' => '{}', 'revision' => 1]);
        } catch (UniqueConstraintViolationException) {
        }
    }
}
