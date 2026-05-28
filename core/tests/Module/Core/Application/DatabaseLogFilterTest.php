<?php

declare(strict_types=1);

namespace App\Tests\Module\Core\Application;

use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Application\DatabaseLogFilter;
use PHPUnit\Framework\TestCase;

final class DatabaseLogFilterTest extends TestCase
{
    public function testRoutineInfoEventsAreDroppedByDefault(): void
    {
        $filter = $this->filter(false);

        self::assertFalse($filter->shouldStore('scheduler.heartbeat'));
        self::assertFalse($filter->shouldStore('audit_event_instance_query_checked'));
        self::assertFalse($filter->shouldStore('agent.metrics.batch_recorded', ['ingested' => 5]));
        self::assertFalse($filter->shouldStore('agent.job.completed', ['status' => 'succeeded']));
    }

    public function testWarningsErrorsAndFailedRoutineEventsAreStored(): void
    {
        $filter = $this->filter(false);

        self::assertTrue($filter->shouldStore('scheduler.heartbeat', [], 'warning'));
        self::assertTrue($filter->shouldStore('agent.metrics.batch_recorded', ['level' => 'error']));
        self::assertTrue($filter->shouldStore('agent.job.completed', ['status' => 'failed']));
        self::assertTrue($filter->shouldStore('instance.query.checked', ['error' => 'timeout']));
    }

    public function testSecurityAndAdminEventsAreStored(): void
    {
        $filter = $this->filter(false);

        self::assertTrue($filter->shouldStore('auth.login_failed', ['ip' => '127.0.0.1']));
        self::assertTrue($filter->shouldStore('user.updated', ['user_id' => 1]));
        self::assertTrue($filter->shouldStore('gdpr.export_deleted', ['export_id' => 10]));
    }

    public function testRoutineToggleCanKeepRoutineInfoEvents(): void
    {
        self::assertTrue($this->filter(true)->shouldStore('scheduler.heartbeat'));
    }

    private function filter(bool $storeRoutine): DatabaseLogFilter
    {
        $settings = $this->createMock(AppSettingsService::class);
        $settings->method('shouldStoreRoutineDatabaseLogs')->willReturn($storeRoutine);

        return new DatabaseLogFilter($settings);
    }
}
