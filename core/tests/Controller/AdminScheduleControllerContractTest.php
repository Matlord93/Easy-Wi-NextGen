<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\TestCase;

final class AdminScheduleControllerContractTest extends TestCase
{
    private string $controller;
    private string $indexTemplate;
    private string $historyTemplate;

    protected function setUp(): void
    {
        $this->controller = (string) file_get_contents(__DIR__.'/../../src/Module/PanelAdmin/UI/Controller/Admin/AdminScheduleController.php');
        $this->indexTemplate = (string) file_get_contents(__DIR__.'/../../templates/admin/schedules/index.html.twig');
        $this->historyTemplate = (string) file_get_contents(__DIR__.'/../../templates/admin/schedules/history.html.twig');
    }

    public function testAdminCanSeeAggregatedSchedules(): void
    {
        self::assertStringContainsString("path: '/admin/schedules'", $this->controller);
        self::assertStringContainsString("path: '/admin/cronjobs'", $this->controller);
        self::assertStringContainsString('InternalScheduleProvider', $this->controller);
        self::assertStringContainsString('BackupScheduleRepository', $this->controller);
        self::assertStringContainsString('InstanceScheduleRepository', $this->controller);
        self::assertStringContainsString('Geplante Aufgaben', $this->indexTemplate);
        self::assertStringContainsString('Scheduler', $this->indexTemplate);
        self::assertStringContainsString('Fällige Aufgaben', $this->indexTemplate);
    }

    public function testNonAdminGetsForbidden(): void
    {
        self::assertStringContainsString('requireAdmin', $this->controller);
        self::assertStringContainsString('UnauthorizedHttpException', $this->controller);
        self::assertStringContainsString('AccessDeniedHttpException', $this->controller);
        self::assertStringContainsString('Forbidden', $this->controller);
    }

    public function testDisableAndEnableActionsUpdateExistingScheduleSources(): void
    {
        self::assertStringContainsString('admin_schedules_toggle', $this->controller);
        self::assertStringContainsString('$schedule->update($schedule->getCronExpression()', $this->controller);
        self::assertStringContainsString('$schedule->update($schedule->getAction()', $this->controller);
        self::assertStringContainsString('Deaktivieren', $this->indexTemplate);
        self::assertStringContainsString('Aktivieren', $this->indexTemplate);
    }

    public function testRunNowActionUsesCentralSchedulerAndReturnsCreatedJobs(): void
    {
        self::assertStringContainsString('admin_schedules_run_now', $this->controller);
        self::assertStringContainsString('$this->schedulerRunner->runNow', $this->controller);
        self::assertStringContainsString('created_job_ids', $this->controller);
        self::assertStringContainsString('Jetzt ausführen', $this->indexTemplate);
    }

    public function testHistoryAndLogsAreVisiblePerSchedule(): void
    {
        self::assertStringContainsString('admin_schedules_history', $this->controller);
        self::assertStringContainsString('admin_schedules_logs', $this->controller);
        self::assertStringContainsString('findRecentForSchedule', $this->controller);
        self::assertStringContainsString('startedAt', $this->historyTemplate);
        self::assertStringContainsString('finishedAt', $this->historyTemplate);
        self::assertStringContainsString('durationMs', $this->historyTemplate);
        self::assertStringContainsString('createdJobIds', $this->historyTemplate);
        self::assertStringContainsString('Logs', $this->indexTemplate);
    }

    public function testUnassignedScheduleWarningIsRendered(): void
    {
        $provider = (string) file_get_contents(__DIR__.'/../../src/Module/Core/Application/Scheduler/InternalScheduleProvider.php');

        self::assertStringContainsString('unassigned.backup_schedule', $provider);
        self::assertStringContainsString('Dieser Zeitplan ist gespeichert, aber aktuell keinem aktiven Scheduler-Handler zugeordnet.', $provider);
        self::assertStringContainsString('handler_active', $this->controller);
        self::assertStringContainsString('keinem aktiven Scheduler-Handler', $this->indexTemplate);
    }
}
