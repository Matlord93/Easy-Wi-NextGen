<?php

declare(strict_types=1);

namespace App\Tests\Command;

use PHPUnit\Framework\TestCase;

final class CentralSchedulerContractTest extends TestCase
{
    public function testRunSchedulesIsCentralProductionSchedulerEntryPoint(): void
    {
        $command = (string) file_get_contents(__DIR__.'/../../src/Module/Core/Command/RunSchedulesCommand.php');
        $docs = (string) file_get_contents(__DIR__.'/../../../docs/architecture/central-scheduler.md');

        self::assertStringContainsString('app:run-schedules', $command);
        self::assertStringContainsString('CentralSchedulerRunner', $command);
        self::assertStringContainsString('$this->centralSchedulerRunner->runDue($now)', $command);
        self::assertStringContainsString('one productive scheduler entry point', $docs);
        self::assertStringContainsString('Do not add', $docs);
    }

    public function testRegistryContainsInitialScheduleHandlers(): void
    {
        $services = (string) file_get_contents(__DIR__.'/../../config/services.yaml');
        $backupHandler = (string) file_get_contents(__DIR__.'/../../src/Module/Gameserver/Application/Scheduler/GameserverAutoBackupScheduleHandler.php');
        $restartHandler = (string) file_get_contents(__DIR__.'/../../src/Module/Gameserver/Application/Scheduler/GameserverAutoRestartScheduleHandler.php');
        $watchdogHandler = (string) file_get_contents(__DIR__.'/../../src/Module/Gameserver/Application/Scheduler/GameserverWatchdogScheduleHandler.php');
        $jobsCleanupHandler = (string) file_get_contents(__DIR__.'/../../src/Module/Core/Application/Scheduler/JobsCleanupScheduleHandler.php');
        $backupsCleanupHandler = (string) file_get_contents(__DIR__.'/../../src/Module/Core/Application/Scheduler/BackupsCleanupScheduleHandler.php');

        self::assertStringContainsString('app.schedule_handler', $services);
        self::assertStringContainsString('gameserver.auto_backup', $backupHandler);
        self::assertStringContainsString('gameserver.auto_restart', $restartHandler);
        self::assertStringContainsString('gameserver.watchdog', $watchdogHandler);
        self::assertStringContainsString('cleanup.jobs', $jobsCleanupHandler);
        self::assertStringContainsString('cleanup.backups', $backupsCleanupHandler);
    }

    public function testAppRunSchedulesProcessesGameserverBackupAndRestartHandlers(): void
    {
        $backupHandler = (string) file_get_contents(__DIR__.'/../../src/Module/Gameserver/Application/Scheduler/GameserverAutoBackupScheduleHandler.php');
        $restartHandler = (string) file_get_contents(__DIR__.'/../../src/Module/Gameserver/Application/Scheduler/GameserverAutoRestartScheduleHandler.php');

        self::assertStringContainsString('GameserverBackupScheduleRunner', $backupHandler);
        self::assertStringContainsString('instance.backup.create', (string) file_get_contents(__DIR__.'/../../src/Module/Gameserver/Application/GameserverBackupScheduleRunner.php'));
        self::assertStringContainsString('GameserverInstanceScheduleRunner', $restartHandler);
        self::assertStringContainsString("new Job('instance.restart'", (string) file_get_contents(__DIR__.'/../../src/Module/Gameserver/Application/GameserverInstanceScheduleRunner.php'));
    }

    public function testFeatureSpecificCommandsAreOptionalDebugHelpers(): void
    {
        $docs = (string) file_get_contents(__DIR__.'/../../../docs/architecture/central-scheduler.md');

        self::assertStringContainsString('debug/development helpers only', $docs);
        self::assertStringContainsString('must not be required as separate production cronjobs', $docs);
    }

    public function testAdminUiListsBackupAndInstanceSchedulesAndRunNow(): void
    {
        $controller = (string) file_get_contents(__DIR__.'/../../src/Module/PanelAdmin/UI/Controller/Admin/AdminScheduleController.php');
        $template = (string) file_get_contents(__DIR__.'/../../templates/admin/schedules/index.html.twig');
        $provider = (string) file_get_contents(__DIR__.'/../../src/Module/Core/Application/Scheduler/InternalScheduleProvider.php');
        $backupHandler = (string) file_get_contents(__DIR__.'/../../src/Module/Gameserver/Application/Scheduler/GameserverAutoBackupScheduleHandler.php');
        $restartHandler = (string) file_get_contents(__DIR__.'/../../src/Module/Gameserver/Application/Scheduler/GameserverAutoRestartScheduleHandler.php');

        self::assertStringContainsString("'/admin/schedules'", $controller);
        self::assertStringContainsString('InternalScheduleProvider', $controller);
        self::assertStringContainsString('runNow', $controller);
        self::assertStringContainsString('Jetzt ausführen', $template);
        self::assertStringContainsString('BackupSchedule', $backupHandler);
        self::assertStringContainsString('InstanceSchedule', $restartHandler);
        self::assertStringContainsString("findBy([], ['id' => 'ASC'])", $backupHandler);
        self::assertStringContainsString('admin_schedules_toggle', $controller);
        self::assertStringContainsString('schedules()', $provider);
    }

    public function testRunNowAndParallelRunsUseSameRunnersAndLocks(): void
    {
        $centralRunner = (string) file_get_contents(__DIR__.'/../../src/Module/Core/Application/Scheduler/CentralSchedulerRunner.php');
        $command = (string) file_get_contents(__DIR__.'/../../src/Module/Core/Command/RunSchedulesCommand.php');
        $backupRunner = (string) file_get_contents(__DIR__.'/../../src/Module/Gameserver/Application/GameserverBackupScheduleRunner.php');
        $restartRunner = (string) file_get_contents(__DIR__.'/../../src/Module/Gameserver/Application/GameserverInstanceScheduleRunner.php');

        self::assertStringContainsString('runNow', $centralRunner);
        self::assertStringContainsString('easywi-run-schedules.lock', $command);
        self::assertStringContainsString('LOCK_EX | LOCK_NB', $command);
        self::assertStringContainsString('runScheduleNow', $backupRunner);
        self::assertStringContainsString('runScheduleNow', $restartRunner);
        self::assertStringContainsString('findLatestActiveByTypeInstanceIdAndScheduleId', $restartRunner);
    }

    public function testScheduleHistoryAndErrorsArePersisted(): void
    {
        $entity = (string) file_get_contents(__DIR__.'/../../src/Module/Core/Domain/Entity/ScheduledTaskRun.php');
        $migration = (string) file_get_contents(__DIR__.'/../../migrations/Version20260506120000.php');
        $centralRunner = (string) file_get_contents(__DIR__.'/../../src/Module/Core/Application/Scheduler/CentralSchedulerRunner.php');
        $template = (string) file_get_contents(__DIR__.'/../../templates/admin/schedules/index.html.twig');

        self::assertStringContainsString('ScheduledTaskRun', $entity);
        self::assertStringContainsString('createdJobIds', $entity);
        self::assertStringContainsString('durationMs', $entity);
        self::assertStringContainsString('scheduled_task_runs', $migration);
        self::assertStringContainsString('ScheduleExecutionResult::failed', $centralRunner);
        self::assertStringContainsString('recordHistory', (string) file_get_contents(__DIR__.'/../../src/Module/Gameserver/Application/GameserverBackupScheduleRunner.php'));
        self::assertStringContainsString('recordHistory', (string) file_get_contents(__DIR__.'/../../src/Module/Gameserver/Application/GameserverInstanceScheduleRunner.php'));
        self::assertStringContainsString('message', $template);
    }
}
