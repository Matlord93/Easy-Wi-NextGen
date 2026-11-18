<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Lock\LockFactory;

final readonly class JobHistoryRetention
{
    public function __construct(private Connection $db, private AppSettingsServiceInterface $settings, private JobHistoryPolicy $policy, private LockFactory $locks) {}

    /** @return array{jobs_deleted:int,agent_jobs_deleted:int,task_runs_deleted:int} */
    public function cleanup(?\DateTimeImmutable $now = null): array
    {
        $now = ($now ?? new \DateTimeImmutable())->setTimezone(new \DateTimeZone('UTC'));
        $summary = ['jobs_deleted' => 0, 'agent_jobs_deleted' => 0, 'task_runs_deleted' => 0];
        $lock = $this->locks->createLock('core.job_history.retention', 300);
        if (!$lock->acquire()) { return $summary; }
        try {
            $settings = $this->settings->getSettings();
            $routineDays = self::days($settings[AppSettingsService::KEY_DATABASE_LOGGING_ROUTINE_RETENTION_DAYS] ?? null, 30);
            $errorDays = self::days($settings[AppSettingsService::KEY_DATABASE_LOGGING_ERROR_RETENTION_DAYS] ?? null, 180);
            $parameters = [
                'routine' => $now->modify('-'.$routineDays.' days')->format('Y-m-d H:i:s'),
                'errors' => $now->modify('-'.$errorDays.' days')->format('Y-m-d H:i:s'),
                // Results remain available for polling and dependent workflows.
                'operational' => $now->modify('-1 day')->format('Y-m-d H:i:s'),
            ];
            $customerJobs = [];
            foreach (JobHistoryPolicy::CUSTOMER_PATTERNS as $index => $pattern) {
                $customerJobs[] = 'j.type LIKE :customer_'.$index;
                $parameters['customer_'.$index] = $pattern;
            }
            $customerRuns = "(r.schedule_source IN ('instance_schedule', 'backup_schedule') OR r.type LIKE 'gameserver.%')";
            $mode = $this->policy->mode();
            $jobCutoff = match ($mode) {
                'customers' => 'CASE WHEN ('.implode(' OR ', $customerJobs).') THEN :routine ELSE :operational END',
                'errors' => ':operational',
                default => ':routine',
            };
            if ($mode !== 'customers') {
                foreach (array_keys($parameters) as $key) {
                    if (str_starts_with($key, 'customer_')) { unset($parameters[$key]); }
                }
            }
            if ($mode === 'errors') { unset($parameters['routine']); }
            $jobWhere = "j.status IN ('succeeded', 'failed', 'cancelled') AND j.updated_at < :operational AND ((j.status = 'failed' AND j.updated_at < :errors) OR (j.status <> 'failed' AND j.updated_at < ".$jobCutoff.")) AND NOT EXISTS (SELECT 1 FROM backups b WHERE b.job_id = j.id)";
            for ($step = 0; $step < 10; ++$step) {
                $deleted = $this->db->transactional(function (Connection $db) use ($jobWhere, $parameters): int {
                    $ids = $db->fetchFirstColumn('SELECT j.id FROM jobs j WHERE '.$jobWhere.' ORDER BY j.updated_at, j.id LIMIT 500', $parameters);
                    if ($ids === []) { return 0; }
                    $types = ['ids' => ArrayParameterType::STRING];
                    $values = ['ids' => $ids];
                    $db->executeStatement('DELETE FROM job_logs WHERE job_id IN (:ids)', $values, $types);
                    $db->executeStatement('DELETE FROM job_results WHERE job_id IN (:ids)', $values, $types);
                    return (int) $db->executeStatement('DELETE FROM jobs WHERE id IN (:ids)', $values, $types);
                });
                $summary['jobs_deleted'] += $deleted;
                if ($deleted < 500) { break; }
            }
            $agentCutoff = str_replace('j.type', 'a.type', $jobCutoff);
            for ($step = 0; $step < 10; ++$step) {
                $sql = "DELETE FROM agent_jobs WHERE id IN (SELECT id FROM (SELECT a.id FROM agent_jobs a WHERE a.status IN ('success', 'failed') AND a.finished_at < :operational AND ((a.status = 'failed' AND a.finished_at < :errors) OR (a.status <> 'failed' AND a.finished_at < ".$agentCutoff.')) ORDER BY a.finished_at, a.id LIMIT 500) expired_agent_jobs)';
                $deleted = (int) $this->db->executeStatement($sql, $parameters);
                $summary['agent_jobs_deleted'] += $deleted;
                if ($deleted < 500) { break; }
            }
            $runCutoff = match ($mode) {
                'customers' => 'CASE WHEN '.$customerRuns." AND r.status <> 'skipped' THEN :routine ELSE :operational END",
                'errors' => ':operational',
                default => ':routine',
            };
            $runParameters = array_filter($parameters, static fn (string $key): bool => !str_starts_with($key, 'customer_'), ARRAY_FILTER_USE_KEY);
            for ($step = 0; $step < 10; ++$step) {
                $sql = "DELETE FROM scheduled_task_runs WHERE id IN (SELECT id FROM (SELECT r.id FROM scheduled_task_runs r WHERE r.status IN ('success', 'failed', 'skipped') AND r.started_at < :operational AND ((r.status = 'failed' AND r.started_at < :errors) OR (r.status <> 'failed' AND r.started_at < ".$runCutoff.')) ORDER BY r.started_at, r.id LIMIT 1000) expired_runs)';
                $deleted = (int) $this->db->executeStatement($sql, $runParameters);
                $summary['task_runs_deleted'] += $deleted;
                if ($deleted < 1000) { break; }
            }
            return $summary;
        } finally {
            $lock->release();
        }
    }

    private static function days(mixed $value, int $default): int
    {
        $days = filter_var($value, FILTER_VALIDATE_INT);
        return is_int($days) && $days > 0 ? $days : $default;
    }
}
