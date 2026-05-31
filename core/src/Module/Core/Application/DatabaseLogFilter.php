<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

final class DatabaseLogFilter
{
    /**
     * These are ALWAYS dropped (success only) regardless of the routine-log setting.
     * Errors and warnings from these actions are still stored.
     *
     * @var array<string, true>
     */
    private const NEVER_STORE_ACTIONS = [
        'instance.query.check'               => true,
        'instance.query.checked'             => true,
        'audit_event_instance_query_checked' => true,
    ];

    /** @var array<string, true> */
    private const HIGH_FREQUENCY_ROUTINE_ACTIONS = [
        'scheduler.heartbeat' => true,
        'agent.heartbeat' => true,
        'agent.metrics_ingested' => true,
        'agent.metrics_batch_ingested' => true,
        'agent.metrics.batch_recorded' => true,
        'agent.job_completed' => true,
        'agent.job.completed' => true,
        'public_server.status_checked' => true,
    ];

    /** @var array<int, string> */
    private const SECURITY_AND_ADMIN_PREFIXES = [
        'auth.',
        'user.',
        'role.',
        'permission.',
        'api_token.',
        'agent.registered',
        'agent.secret_',
        'agent.bootstrap_',
        'server.',
        'webspace.',
        'instance.create',
        'instance.update',
        'instance.delete',
        'update.',
        'gdpr.',
        'security.',
    ];

    /** @param list<string> $dropInfoEvents */
    public function __construct(
        private readonly AppSettingsService $settingsService,
        private readonly array $dropInfoEvents = [],
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function shouldStore(string $action, array $payload = [], ?string $level = null): bool
    {
        $normalizedLevel = strtolower((string) ($level ?? $payload['level'] ?? $payload['severity'] ?? 'info'));
        if (in_array($normalizedLevel, ['warning', 'warn', 'error', 'critical', 'alert', 'emergency'], true)) {
            return true;
        }

        if ($this->isErrorPayload($payload)) {
            return true;
        }

        if (isset(self::NEVER_STORE_ACTIONS[$action])) {
            return false;
        }

        if ($this->isProtectedAction($action)) {
            return true;
        }

        if (!$this->isRoutineAction($action)) {
            return true;
        }

        return $this->settingsService->shouldStoreRoutineDatabaseLogs();
    }

    /** @param array<string, mixed> $payload */
    private function isErrorPayload(array $payload): bool
    {
        $status = strtolower((string) ($payload['status'] ?? $payload['result'] ?? ''));
        if (in_array($status, ['failed', 'failure', 'error', 'errored', 'warning', 'cancelled'], true)) {
            return true;
        }

        return isset($payload['error']) || isset($payload['exception']) || isset($payload['error_code']);
    }

    private function isProtectedAction(string $action): bool
    {
        foreach (self::SECURITY_AND_ADMIN_PREFIXES as $prefix) {
            if (str_starts_with($action, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function isRoutineAction(string $action): bool
    {
        if (in_array($action, $this->dropInfoEvents, true) || isset(self::HIGH_FREQUENCY_ROUTINE_ACTIONS[$action])) {
            return true;
        }

        foreach (['.heartbeat', '.metrics_ingested', '.metrics_batch_ingested', '.metrics.batch_recorded', '.job_completed', '.job.completed', '.query.checked', '.status_checked'] as $suffix) {
            if (str_ends_with($action, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
