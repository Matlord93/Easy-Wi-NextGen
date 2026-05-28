<?php

declare(strict_types=1);

namespace App\Tests\Module\Core\Application\Scheduler;

use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Application\PrivacyGdprBackgroundJobResult;
use App\Module\Core\Application\PrivacyGdprBackgroundJobRunnerInterface;
use App\Module\Core\Application\Scheduler\PrivacyGdprScheduleHandler;
use App\Module\Core\Application\Scheduler\ScheduleHandlerRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class PrivacyGdprScheduleHandlerTest extends TestCase
{
    public function testSchedulerRegistryContainsPrivacyGdprHandler(): void
    {
        $handler = $this->newHandler(false, $this->neverRunner());
        $registry = new ScheduleHandlerRegistry([$handler]);

        self::assertSame($handler, $registry->get(PrivacyGdprScheduleHandler::TYPE));
        self::assertSame('privacy_gdpr_background', $handler->schedules()[0]->id);
    }

    public function testDisabledSettingSkipsWithoutRunningJobsAndPersistsLastRun(): void
    {
        $marked = [];
        $handler = $this->newHandler(false, $this->neverRunner(), $marked);

        $result = $handler->runDue(new \DateTimeImmutable('2026-05-28T12:00:00+00:00'));

        self::assertSame('skipped', $result->status);
        self::assertSame('skipped', $marked['status'] ?? null);
        self::assertNull($marked['error'] ?? null);
    }


    public function testDisabledSettingAlsoSkipsManualRunNow(): void
    {
        $marked = [];
        $handler = $this->newHandler(false, $this->neverRunner(), $marked);

        $result = $handler->runNow('system', PrivacyGdprScheduleHandler::SCHEDULE_ID, new \DateTimeImmutable('2026-05-28T12:00:00+00:00'));

        self::assertSame('skipped', $result->status);
        self::assertSame('skipped', $marked['status'] ?? null);
    }

    public function testEnabledSettingRunsPrivacyGdprJobsAndPersistsSuccess(): void
    {
        $marked = [];
        $runner = $this->createMock(PrivacyGdprBackgroundJobRunnerInterface::class);
        $runner->expects(self::once())
            ->method('run')
            ->with(25, 100, self::isInstanceOf(\DateTimeImmutable::class))
            ->willReturn(new PrivacyGdprBackgroundJobResult(['exports_processed' => 1], 'done'));

        $handler = $this->newHandler(true, $runner, $marked);
        $result = $handler->runDue(new \DateTimeImmutable('2026-05-28T12:00:00+00:00'));

        self::assertSame('success', $result->status);
        self::assertSame('success', $marked['status'] ?? null);
        self::assertNull($marked['error'] ?? null);
    }

    public function testFailurePersistsLastError(): void
    {
        $marked = [];
        $runner = $this->createMock(PrivacyGdprBackgroundJobRunnerInterface::class);
        $runner->expects(self::once())->method('run')->willThrowException(new \RuntimeException('boom'));

        $handler = $this->newHandler(true, $runner, $marked);
        $result = $handler->runDue(new \DateTimeImmutable('2026-05-28T12:00:00+00:00'));

        self::assertSame('failed', $result->status);
        self::assertSame('failed', $marked['status'] ?? null);
        self::assertSame('boom', $marked['error'] ?? null);
    }

    /** @param array<string,mixed> $marked */
    private function newHandler(bool $enabled, PrivacyGdprBackgroundJobRunnerInterface $runner, array &$marked = []): PrivacyGdprScheduleHandler
    {
        return new PrivacyGdprScheduleHandler($this->settings($enabled, $marked), $runner, new NullLogger());
    }

    /** @param array<string,mixed> $marked */
    private function settings(bool $enabled, array &$marked): AppSettingsService
    {
        $settings = $this->createMock(AppSettingsService::class);
        $settings->method('getSettings')->willReturn([
            AppSettingsService::KEY_PRIVACY_GDPR_JOBS_ENABLED => $enabled,
            AppSettingsService::KEY_PRIVACY_GDPR_JOBS_INTERVAL => '0 3 * * *',
            AppSettingsService::KEY_PRIVACY_GDPR_LAST_RUN_AT => null,
            AppSettingsService::KEY_PRIVACY_GDPR_LAST_STATUS => null,
            AppSettingsService::KEY_PRIVACY_GDPR_LAST_ERROR => null,
        ]);
        $settings->method('isPrivacyGdprJobsEnabled')->willReturn($enabled);
        $settings->method('getPrivacyGdprJobsInterval')->willReturn('0 3 * * *');
        $settings->method('markPrivacyGdprJobsRun')->willReturnCallback(static function (string $status, ?string $error) use (&$marked): void {
            $marked['status'] = $status;
            $marked['error'] = $error;
        });

        return $settings;
    }

    private function neverRunner(): PrivacyGdprBackgroundJobRunnerInterface
    {
        $runner = $this->createMock(PrivacyGdprBackgroundJobRunnerInterface::class);
        $runner->expects(self::never())->method('run');

        return $runner;
    }
}
