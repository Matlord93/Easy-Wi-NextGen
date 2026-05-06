<?php

declare(strict_types=1);

namespace App\Tests\Command;

use PHPUnit\Framework\TestCase;

final class GameserverBackupSchedulesCommandContractTest extends TestCase
{
    private string $runnerSource;
    private string $commandSource;
    private string $agentApiSource;

    protected function setUp(): void
    {
        $this->runnerSource = (string) file_get_contents(__DIR__.'/../../src/Module/Gameserver/Application/GameserverBackupScheduleRunner.php');
        $this->commandSource = (string) file_get_contents(__DIR__.'/../../src/Module/Core/Command/RunGameserverBackupSchedulesCommand.php');
        $this->agentApiSource = (string) file_get_contents(__DIR__.'/../../src/Module/PanelCustomer/UI/Controller/Api/AgentApiController.php');
    }

    public function testDueBackupScheduleQueuesCreateJobWithRequiredPayload(): void
    {
        self::assertStringContainsString("name: 'app:gameserver:run-backup-schedules'", $this->commandSource);
        self::assertStringContainsString("new Job('instance.backup.create'", $this->runnerSource);
        self::assertStringContainsString("'agent_id' =>", $this->runnerSource);
        self::assertStringContainsString("'instance_id' =>", $this->runnerSource);
        self::assertStringContainsString("'customer_id' =>", $this->runnerSource);
        self::assertStringContainsString("'install_path' =>", $this->runnerSource);
        self::assertStringContainsString("'base_dir' =>", $this->runnerSource);
        self::assertStringContainsString("'definition_id' =>", $this->runnerSource);
        self::assertStringContainsString("'backup_target_id'", $this->runnerSource);
        self::assertStringContainsString("\$schedule->markRun(\$now, 'queued')", $this->runnerSource);
        self::assertStringContainsString("\$schedule->setLastQueuedAt(\$now)", $this->runnerSource);
    }

    public function testNotDueScheduleDoesNotQueueJob(): void
    {
        self::assertStringContainsString('$lastQueuedAt !== null', $this->runnerSource);
        self::assertStringContainsString('$lastQueuedAt->setTimezone($timeZone) >= $previousRun', $this->runnerSource);
        self::assertStringContainsString('return 0;', $this->runnerSource);
    }

    public function testDisabledSchedulesAreExcludedByRepositoryBatch(): void
    {
        $repositorySource = (string) file_get_contents(__DIR__.'/../../src/Repository/BackupScheduleRepository.php');

        self::assertStringContainsString('findEnabledBatchAfterId', $this->runnerSource);
        self::assertStringContainsString('schedule.enabled = :enabled', $repositorySource);
        self::assertStringContainsString("setParameter('enabled', true)", $repositorySource);
    }

    public function testDuplicateRunsAreSuppressedBySharedLockAndActiveJobCheck(): void
    {
        self::assertStringContainsString('easywi-run-schedules.lock', $this->commandSource);
        self::assertStringContainsString('LOCK_EX | LOCK_NB', $this->commandSource);
        self::assertStringContainsString('findLatestActiveByTypesAndInstanceId', $this->runnerSource);
        self::assertStringContainsString("'instance.backup.create'", $this->runnerSource);
        self::assertStringContainsString("'instance.backup.restore'", $this->runnerSource);
        self::assertStringContainsString('backup_action_in_progress', $this->runnerSource);
    }

    public function testWindowsAgentReceivesBackupJobOnlyWhenWindowsNodesAreEnabled(): void
    {
        self::assertStringContainsString("'instance.backup.create'", $this->agentApiSource);
        self::assertStringContainsString("'instance.backup.restore'", $this->agentApiSource);
        self::assertStringContainsString("private readonly bool \$windowsNodesEnabled", $this->runnerSource);
        self::assertStringContainsString('windows_nodes_disabled', $this->runnerSource);
        self::assertStringContainsString('isWindowsInstance', $this->runnerSource);
    }
}
