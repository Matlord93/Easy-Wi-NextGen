<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Scheduler;

use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Application\PrivacyGdprBackgroundJobRunnerInterface;
use Cron\CronExpression;
use Psr\Log\LoggerInterface;

final class PrivacyGdprScheduleHandler implements ScheduleHandlerInterface
{
    public const TYPE = 'privacy.gdpr_background';
    public const SCHEDULE_ID = 'privacy_gdpr_background';

    public function __construct(
        private readonly AppSettingsService $settingsService,
        private readonly PrivacyGdprBackgroundJobRunnerInterface $backgroundJobService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function schedules(): array
    {
        $settings = $this->settingsService->getSettings();
        $interval = $this->settingsService->getPrivacyGdprJobsInterval();
        $lastRunAt = $this->parseDate($settings[AppSettingsService::KEY_PRIVACY_GDPR_LAST_RUN_AT] ?? null);
        $enabled = $this->settingsService->isPrivacyGdprJobsEnabled();

        return [new InternalSchedule(
            'system',
            self::SCHEDULE_ID,
            'Privacy & GDPR',
            self::TYPE,
            'gdpr',
            $interval,
            $enabled,
            [
                'privacy_gdpr' => true,
                'description' => 'Datenschutz-/GDPR-bezogene Hintergrundaufgaben',
                'automation_can_be_disabled' => true,
            ],
            $lastRunAt,
            null,
            $enabled ? $this->nextRunAt($interval) : null,
            is_string($settings[AppSettingsService::KEY_PRIVACY_GDPR_LAST_STATUS] ?? null) ? $settings[AppSettingsService::KEY_PRIVACY_GDPR_LAST_STATUS] : null,
            is_string($settings[AppSettingsService::KEY_PRIVACY_GDPR_LAST_ERROR] ?? null) ? $settings[AppSettingsService::KEY_PRIVACY_GDPR_LAST_ERROR] : null,
        )];
    }

    public function runDue(?\DateTimeImmutable $now = null): ScheduleExecutionResult
    {
        return $this->runInternal(false, $now);
    }

    public function runNow(string $source, string $id, ?\DateTimeImmutable $now = null): ScheduleExecutionResult
    {
        if ($source !== 'system' || $id !== self::SCHEDULE_ID) {
            return ScheduleExecutionResult::failed('Unknown Privacy/GDPR schedule.');
        }

        return $this->runInternal(true, $now);
    }

    private function runInternal(bool $force, ?\DateTimeImmutable $now = null): ScheduleExecutionResult
    {
        $now ??= new \DateTimeImmutable();
        if (!$this->settingsService->isPrivacyGdprJobsEnabled()) {
            $message = 'Privacy/GDPR background jobs disabled; skipping.';
            $this->settingsService->markPrivacyGdprJobsRun('skipped', null, $now);
            $this->logger->info('privacy_gdpr.background_jobs_skipped', ['reason' => 'disabled']);

            return ScheduleExecutionResult::skipped($message);
        }

        if (!$force && !$this->isDue($now)) {
            $message = 'Privacy/GDPR background jobs are not due yet.';
            $this->logger->info('privacy_gdpr.background_jobs_skipped', ['reason' => 'not_due']);

            return ScheduleExecutionResult::skipped($message);
        }

        try {
            $result = $this->backgroundJobService->run(25, 100, $now);
            $this->settingsService->markPrivacyGdprJobsRun('success', null, $now);

            return ScheduleExecutionResult::success($result->message);
        } catch (\Throwable $exception) {
            $this->settingsService->markPrivacyGdprJobsRun('failed', $exception->getMessage(), $now);
            $this->logger->error('privacy_gdpr.background_jobs_failed', ['exception' => $exception]);

            return ScheduleExecutionResult::failed($exception->getMessage());
        }
    }


    private function isDue(\DateTimeImmutable $now): bool
    {
        $settings = $this->settingsService->getSettings();
        $lastRunAt = $this->parseDate($settings[AppSettingsService::KEY_PRIVACY_GDPR_LAST_RUN_AT] ?? null);
        if ($lastRunAt === null) {
            return true;
        }

        $cronExpression = $this->settingsService->getPrivacyGdprJobsInterval();
        if (!CronExpression::isValidExpression($cronExpression)) {
            return true;
        }

        try {
            $previous = CronExpression::factory($cronExpression)->getPreviousRunDate($now, 0, true);
            $previousRunAt = \DateTimeImmutable::createFromMutable($previous)->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return true;
        }

        return $lastRunAt < $previousRunAt;
    }

    private function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function nextRunAt(string $cronExpression): ?\DateTimeImmutable
    {
        if (!CronExpression::isValidExpression($cronExpression)) {
            return null;
        }

        try {
            $cron = CronExpression::factory($cronExpression);
            $next = $cron->getNextRunDate(new \DateTimeImmutable('now', new \DateTimeZone('UTC')), 0, true);

            return \DateTimeImmutable::createFromMutable($next)->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }
}
