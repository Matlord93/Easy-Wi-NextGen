<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Update\UpdateResult;
use App\Module\Setup\Application\WebinterfaceUpdateServiceInterface;

final class PanelUpdateTickProcessor
{
    private const LOCK_STALE_AFTER_SECONDS = 900;
    private const TICK_TIME_LIMIT_SECONDS = 60;

    public function __construct(
        private readonly UpdateJobServiceInterface $jobService,
        private readonly WebinterfaceUpdateServiceInterface $updateService,
    ) {
    }

    /**
     * @return array{job: array<string, mixed>|null, locked: bool, notFound: bool}
     */
    public function tick(string $jobId): array
    {
        $job = $this->jobService->getJob($jobId);
        if ($job === null) {
            return ['job' => null, 'locked' => false, 'notFound' => true];
        }

        $lockHandle = $this->acquireJobLock($jobId);
        if ($lockHandle === null) {
            $job['lock'] = [
                'active' => true,
                'message' => 'Ein anderer Tick verarbeitet diesen Update-Job bereits.',
            ];

            return ['job' => $job, 'locked' => true, 'notFound' => false];
        }

        try {
            @set_time_limit(self::TICK_TIME_LIMIT_SECONDS);

            $job = $this->jobService->getJob($jobId) ?? $job;
            if (in_array(($job['status'] ?? null), ['success', 'failed'], true)) {
                return ['job' => $job, 'locked' => false, 'notFound' => false];
            }

            $jobPath = $this->jobPath($jobId);
            $steps = $this->stepsForType((string) ($job['type'] ?? 'update'));
            if ($steps === []) {
                $job = $this->failJob($job, 'unknown_job_type', 'Unbekannter Job-Typ.');
                $this->writeJob($jobPath, $job);

                return ['job' => $job, 'locked' => false, 'notFound' => false];
            }

            $step = $this->resolveNextStep($job, $steps);
            if ($step === null) {
                $job = $this->succeedJob($job);
                $this->writeJob($jobPath, $job);

                return ['job' => $job, 'locked' => false, 'notFound' => false];
            }

            $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
            $job['status'] = 'running';
            $job['startedAt'] = $job['startedAt'] ?? $now;
            $job['lastStep'] = $job['currentStep'] ?? 'created';
            $job['currentStep'] = $step;
            $job['nextStep'] = $this->nextStepAfter($steps, $step);
            $job['lock'] = [
                'active' => true,
                'owner' => 'panel-http-tick',
                'startedAt' => $now,
                'expiresAfterSeconds' => self::LOCK_STALE_AFTER_SECONDS,
            ];
            $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
            $payload['panel_tick'] = [
                'timeLimitSeconds' => self::TICK_TIME_LIMIT_SECONDS,
                'legacySynchronousSteps' => ['apply_update', 'apply_migrations'],
                'note' => 'Panel ticks execute one workflow phase per request; update/migration internals still use the existing synchronous service and are persisted before execution.',
            ];
            $job['payload'] = $payload;
            $this->writeJob($jobPath, $job);
            $this->appendLog($job, 'Update-Schritt läuft: ' . $step);
            if (in_array($step, ['apply_update', 'apply_migrations'], true)) {
                $this->appendLog($job, 'Hinweis: Dieser HTTP-Tick führt einen bestehenden synchronen Service-Schritt aus. Der Job-Status wurde vor dem Start gespeichert; ein feinerer Step-Runner bleibt die nächste Ausbaustufe.');
            }

            $result = $this->runStep($step, $payload);
            $job = $this->jobService->getJob($jobId) ?? $job;
            $job['lastStep'] = $step;
            $job['currentStep'] = $step;
            $job['lock'] = ['active' => false];
            if ($result->logPath !== null) {
                $job['logPath'] = $result->logPath;
            }

            // Persist reload job ID so the await step can poll it across ticks.
            if ($result->reloadJobId !== null) {
                $payload['reload_job_id'] = $result->reloadJobId;
                $job['payload'] = $payload;
            }

            if (!$result->success) {
                $job = $this->failJob($job, $result->error ?? 'update_failed', $result->message);
                $this->appendLog($job, 'Update fehlgeschlagen: ' . ($job['error'] ?? $result->message));
                $this->writeJob($jobPath, $job);

                return ['job' => $job, 'locked' => false, 'notFound' => false];
            }

            // Step not done yet (e.g. awaiting agent reload) — retry same step next tick.
            if ($result->pending) {
                $job['status'] = 'running';
                $job['nextStep'] = $step;
                $job['exitCode'] = null;
                $job['finishedAt'] = null;
                $this->appendLog($job, 'Schritt ' . $step . ' noch ausstehend – nächster Tick.');
                $this->writeJob($jobPath, $job);

                return ['job' => $job, 'locked' => false, 'notFound' => false];
            }

            $nextStep = $this->nextStepAfter($steps, $step);
            if ($nextStep === null) {
                $job = $this->succeedJob($job);
                $this->appendLog($job, 'Update abgeschlossen.');
            } else {
                $job['status'] = 'running';
                $job['nextStep'] = $nextStep;
                $job['exitCode'] = null;
                $job['finishedAt'] = null;
            }
            $this->writeJob($jobPath, $job);

            return ['job' => $job, 'locked' => false, 'notFound' => false];
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    /** @return array<int, string> */
    private function stepsForType(string $type): array
    {
        return match ($type) {
            'update' => ['apply_update'],
            'migrate' => ['apply_migrations'],
            'both' => ['apply_update', 'apply_migrations'],
            default => [],
        };
    }

    /** @param array<string, mixed> $job @param array<int, string> $steps */
    private function resolveNextStep(array $job, array $steps): ?string
    {
        $candidate = is_string($job['nextStep'] ?? null) ? $job['nextStep'] : null;
        if ($candidate !== null && in_array($candidate, $steps, true)) {
            return $candidate;
        }

        $current = is_string($job['currentStep'] ?? null) ? $job['currentStep'] : null;
        if ($current !== null && in_array($current, $steps, true)) {
            return $this->nextStepAfter($steps, $current);
        }

        return $steps[0] ?? null;
    }

    /** @param array<int, string> $steps */
    private function nextStepAfter(array $steps, string $currentStep): ?string
    {
        $index = array_search($currentStep, $steps, true);
        if ($index === false) {
            return $steps[0] ?? null;
        }

        return $steps[$index + 1] ?? null;
    }

    /** @param array<string, mixed> $jobPayload */
    private function runStep(string $step, array $jobPayload = []): UpdateResult
    {
        return match ($step) {
            'apply_update' => $this->updateService->applyUpdate(),
            'apply_migrations' => $this->updateService->applyMigrations(),
            'await_agent_reload' => $this->updateService->awaitAgentReload(
                is_string($jobPayload['reload_job_id'] ?? null) ? (string) $jobPayload['reload_job_id'] : ''
            ),
            default => new UpdateResult(false, 'Unbekannter Update-Schritt.', 'unknown_step: ' . $step, null, null, null),
        };
    }

    /** @param array<string, mixed> $job @return array<string, mixed> */
    private function failJob(array $job, string $error, string $message): array
    {
        $job['status'] = 'failed';
        $job['finishedAt'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $job['exitCode'] = 1;
        $job['error'] = $error !== '' ? $error : $message;
        $job['nextStep'] = null;
        $job['lock'] = ['active' => false];

        return $job;
    }

    /** @param array<string, mixed> $job @return array<string, mixed> */
    private function succeedJob(array $job): array
    {
        $job['status'] = 'success';
        $job['finishedAt'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $job['exitCode'] = 0;
        $job['currentStep'] = 'done';
        $job['nextStep'] = null;
        unset($job['error']);
        $job['lock'] = ['active' => false];

        return $job;
    }

    private function acquireJobLock(string $jobId): mixed
    {
        $lockPath = $this->lockPath($jobId);
        // Stale lock files are harmless because flock() is released by the OS when a request dies.
        // Keep the file, acquire the real advisory lock, then overwrite stale metadata below.
        $handle = @fopen($lockPath, 'c+');
        if (!is_resource($handle)) {
            return null;
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }

        ftruncate($handle, 0);
        fwrite($handle, json_encode([
            'jobId' => $jobId,
            'lockedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'owner' => 'panel-http-tick',
        ], JSON_UNESCAPED_SLASHES) ?: '');
        fflush($handle);

        return $handle;
    }

    private function jobPath(string $jobId): string
    {
        return rtrim($this->jobService->getJobsDir(), '/') . '/' . $jobId . '.json';
    }

    private function lockPath(string $jobId): string
    {
        return rtrim($this->jobService->getJobsDir(), '/') . '/' . $jobId . '.lock';
    }

    /** @param array<string, mixed> $job */
    private function writeJob(string $jobPath, array $job): void
    {
        file_put_contents($jobPath, (string) json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    /** @param array<string, mixed> $job */
    private function appendLog(array $job, string $message): void
    {
        $logPath = is_string($job['logPath'] ?? null) ? $job['logPath'] : null;
        if ($logPath === null || $logPath === '') {
            return;
        }

        @file_put_contents($logPath, '[' . date('c') . '] ' . $message . PHP_EOL, FILE_APPEND);
    }
}
