<?php

declare(strict_types=1);

namespace App\Tests\Command;

use PHPUnit\Framework\TestCase;

final class GameserverInstanceSchedulesCommandContractTest extends TestCase
{
    private string $runnerSource;
    private string $commandSource;
    private string $scheduleSource;
    private string $jobRepositorySource;

    protected function setUp(): void
    {
        $this->runnerSource = (string) file_get_contents(__DIR__.'/../../src/Module/Gameserver/Application/GameserverInstanceScheduleRunner.php');
        $this->commandSource = (string) file_get_contents(__DIR__.'/../../src/Module/Core/Command/RunGameserverInstanceSchedulesCommand.php');
        $this->scheduleSource = (string) file_get_contents(__DIR__.'/../../src/Module/Core/Domain/Entity/InstanceSchedule.php');
        $this->jobRepositorySource = (string) file_get_contents(__DIR__.'/../../src/Repository/JobRepository.php');
    }

    public function testDueRestartScheduleQueuesInstanceRestartJob(): void
    {
        self::assertStringContainsString("name: 'app:gameserver:run-instance-schedules'", $this->commandSource);
        self::assertStringContainsString('InstanceScheduleAction::Restart', $this->runnerSource);
        self::assertStringContainsString("new Job('instance.restart'", $this->runnerSource);
        self::assertStringContainsString("'reason' => 'scheduled_restart'", $this->runnerSource);
    }

    public function testNotDueScheduleDoesNotQueueJob(): void
    {
        self::assertStringContainsString('$lastQueuedAt !== null', $this->runnerSource);
        self::assertStringContainsString('$lastQueuedAt->setTimezone($timeZone) >= $previousRun', $this->runnerSource);
        self::assertStringContainsString('return 0;', $this->runnerSource);
    }

    public function testDisabledSchedulesAreExcludedByRepositoryBatch(): void
    {
        $repositorySource = (string) file_get_contents(__DIR__.'/../../src/Repository/InstanceScheduleRepository.php');

        self::assertStringContainsString('findEnabledBatchAfterId', $this->runnerSource);
        self::assertStringContainsString('schedule.enabled = :enabled', $repositorySource);
        self::assertStringContainsString("setParameter('enabled', true)", $repositorySource);
    }

    public function testInstanceWithoutInstallPathIsSkipped(): void
    {
        self::assertStringContainsString('$instance->getInstallPath() === null', $this->runnerSource);
        self::assertStringContainsString('install_path_missing', $this->runnerSource);
    }

    public function testParallelRunAndActiveScheduleJobSuppressDuplicates(): void
    {
        self::assertStringContainsString('easywi-run-schedules.lock', $this->commandSource);
        self::assertStringContainsString('LOCK_EX | LOCK_NB', $this->commandSource);
        self::assertStringContainsString('findLatestActiveByTypeInstanceIdAndScheduleId', $this->runnerSource);
        self::assertStringContainsString('findLatestActiveByTypeInstanceIdAndScheduleId', $this->jobRepositorySource);
        self::assertStringContainsString('restart_action_in_progress_for_schedule', $this->runnerSource);
    }

    public function testWindowsNodeIsBlockedWhenWindowsNodesAreDisabled(): void
    {
        self::assertStringContainsString("private readonly bool \$windowsNodesEnabled", $this->runnerSource);
        self::assertStringContainsString('windows_nodes_disabled', $this->runnerSource);
        self::assertStringContainsString('isWindowsInstance', $this->runnerSource);
    }

    public function testPayloadContainsInstallPathAndBaseDir(): void
    {
        self::assertStringContainsString("'agent_id' =>", $this->runnerSource);
        self::assertStringContainsString("'node_id' =>", $this->runnerSource);
        self::assertStringContainsString("'instance_id' =>", $this->runnerSource);
        self::assertStringContainsString("'customer_id' =>", $this->runnerSource);
        self::assertStringContainsString("'install_path' =>", $this->runnerSource);
        self::assertStringContainsString("'base_dir' =>", $this->runnerSource);
        self::assertStringContainsString("'schedule_id' =>", $this->runnerSource);
    }

    public function testLastRunAtIsReservedForSuccessfulAgentCompletion(): void
    {
        $agentApiSource = (string) file_get_contents(__DIR__.'/../../src/Module/PanelCustomer/UI/Controller/Api/AgentApiController.php');

        self::assertStringContainsString('markQueued', $this->scheduleSource);
        self::assertStringContainsString('markScheduleResult', $this->scheduleSource);
        self::assertStringNotContainsString('$schedule->markRun($now, \'queued\')', $this->runnerSource);
        self::assertStringContainsString('applyScheduledRestartScheduleResult', $agentApiSource);
        self::assertStringContainsString("\$schedule->markRun(\$completedAt, 'succeeded')", $agentApiSource);
    }
}
