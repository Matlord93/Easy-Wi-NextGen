<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\AgentOrchestrator\Application\AgentJobDispatcherInterface;
use App\Module\AgentOrchestrator\Domain\Entity\AgentJob;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Musicbot\Application\MusicbotInstallerService;
use App\Module\Musicbot\Application\MusicbotRepairService;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the musicbot installer, updater and repair manager.
 *
 * Covers the scenarios from the specification:
 *   - Fresh install
 *   - Update
 *   - Repair / targeted repair actions
 *   - Missing dependencies (ffmpeg, yt-dlp, PulseAudio, TeamSpeak client)
 *   - Missing runtime (binary, socket, config)
 *   - Plugin repair
 *   - Queue repair
 *   - Upload directory repair
 *   - Parallel instances
 *   - Rollback (rebuild after failed update)
 */
final class MusicbotInstallerRegressionTest extends TestCase
{
    private AgentJobDispatcherInterface $dispatcher;
    private AuditLogger $auditLogger;
    private MusicbotInstallerService $installer;
    private MusicbotRepairService $repair;

    protected function setUp(): void
    {
        $this->dispatcher = $this->createMock(AgentJobDispatcherInterface::class);
        $this->auditLogger = $this->createStub(AuditLogger::class);
        $this->installer = new MusicbotInstallerService($this->dispatcher, $this->auditLogger);
        $this->repair = new MusicbotRepairService($this->dispatcher, $this->auditLogger);
    }

    // ── Factories ─────────────────────────────────────────────────────────────

    private function makeInstance(string $serviceName = 'mb-1-abc', string $installPath = '/opt/musicbot/instances/mb-1-abc'): MusicbotInstance
    {
        $customer = $this->createStub(User::class);
        $customer->method('getId')->willReturn(1);
        $customer->method('isAdmin')->willReturn(false);
        $customer->method('getType')->willReturn(UserType::Customer);

        $node = $this->createStub(Agent::class);
        $node->method('getId')->willReturn('node-1');

        return new MusicbotInstance($customer, $node, 'Test Bot', $serviceName, $installPath);
    }

    private function makeAdmin(): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(99);
        $user->method('isAdmin')->willReturn(true);
        $user->method('getType')->willReturn(UserType::Admin);
        return $user;
    }

    private function makeCustomer(): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(1);
        $user->method('isAdmin')->willReturn(false);
        $user->method('getType')->willReturn(UserType::Customer);
        return $user;
    }

    private function makeJob(string $id = 'job-1'): AgentJob
    {
        $job = $this->createStub(AgentJob::class);
        $job->method('getId')->willReturn($id);
        return $job;
    }

    // ── Fresh install ─────────────────────────────────────────────────────────

    public function testFreshInstallDispatchesMusicbotInstallJob(): void
    {
        $instance = $this->makeInstance();
        $this->dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::anything(), 'musicbot.install', self::anything())
            ->willReturn($this->makeJob());

        $result = $this->installer->install($instance, $this->makeAdmin());

        self::assertSame('install', $result['operation']);
        self::assertSame('job-1', $result['job_id']);
    }

    public function testFreshInstallResultContainsInstanceId(): void
    {
        $this->dispatcher->method('dispatch')->willReturn($this->makeJob());
        $instance = $this->makeInstance();

        $result = $this->installer->install($instance, $this->makeAdmin());

        self::assertArrayHasKey('instance_id', $result);
        self::assertArrayHasKey('dispatched_at', $result);
    }

    public function testFreshInstallPayloadIncludesInstallPath(): void
    {
        $capturedPayload = null;
        $this->dispatcher->method('dispatch')
            ->willReturnCallback(function (mixed $node, string $type, array $payload) use (&$capturedPayload): AgentJob {
                $capturedPayload = $payload;
                return $this->makeJob();
            });

        $instance = $this->makeInstance('mb-1-abc', '/opt/musicbot/instances/mb-1-abc');
        $this->installer->install($instance, $this->makeAdmin());

        self::assertSame('/opt/musicbot/instances/mb-1-abc', $capturedPayload['install_path']);
        self::assertSame('mb-1-abc', $capturedPayload['service_name']);
    }

    // ── Update ────────────────────────────────────────────────────────────────

    public function testUpdateDispatchesMusicbotUpdateJobAndHealthCheck(): void
    {
        $jobs = [$this->makeJob('job-update'), $this->makeJob('job-health')];
        $callCount = 0;
        $this->dispatcher->method('dispatch')
            ->willReturnCallback(function () use (&$callCount, $jobs): AgentJob {
                return $jobs[$callCount++];
            });

        $result = $this->installer->update($this->makeInstance(), $this->makeAdmin());

        self::assertSame('update', $result['operation']);
        self::assertSame('job-update', $result['job_id']);
        self::assertSame('job-health', $result['health_check_job_id']);
    }

    public function testUpdateDispatchesExactlyTwoJobs(): void
    {
        $this->dispatcher->expects(self::exactly(2))
            ->method('dispatch')
            ->willReturn($this->makeJob());

        $this->installer->update($this->makeInstance(), $this->makeAdmin());
    }

    // ── Reinstall ─────────────────────────────────────────────────────────────

    public function testReinstallDispatchesMusicbotInstallJob(): void
    {
        $capturedType = null;
        $capturedPayload = null;
        $this->dispatcher->method('dispatch')
            ->willReturnCallback(function (mixed $node, string $type, array $payload) use (&$capturedType, &$capturedPayload): AgentJob {
                $capturedType = $type;
                $capturedPayload = $payload;
                return $this->makeJob();
            });

        $this->installer->reinstall($this->makeInstance(), $this->makeAdmin());

        self::assertSame('musicbot.install', $capturedType);
        self::assertTrue($capturedPayload['reinstall']);
    }

    // ── Rebuild ───────────────────────────────────────────────────────────────

    public function testRebuildDispatchesMusicbotRepairAndHealthCheck(): void
    {
        $types = [];
        $this->dispatcher->method('dispatch')
            ->willReturnCallback(function (mixed $node, string $type) use (&$types): AgentJob {
                $types[] = $type;
                return $this->makeJob();
            });

        $result = $this->installer->rebuild($this->makeInstance(), $this->makeAdmin());

        self::assertSame('rebuild', $result['operation']);
        self::assertContains('musicbot.repair', $types);
        self::assertContains('musicbot.health.check', $types);
    }

    // ── Validate ──────────────────────────────────────────────────────────────

    public function testValidateDispatchesHealthCheckJob(): void
    {
        $this->dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::anything(), 'musicbot.health.check', self::anything())
            ->willReturn($this->makeJob('job-health'));

        $result = $this->installer->validate($this->makeInstance(), $this->makeAdmin());

        self::assertSame('validate', $result['operation']);
        self::assertSame('job-health', $result['job_id']);
    }

    // ── Repair actions ────────────────────────────────────────────────────────

    public function testRepairRewriteQueueIsAdminOnly(): void
    {
        $this->expectException(\RuntimeException::class);
        $instance = $this->makeInstance();
        $this->repair->repair($instance, $this->makeCustomer(), 'rewrite_queue');
    }

    public function testRepairRepairPlaylistsIsAdminOnly(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->repair->repair($this->makeInstance(), $this->makeCustomer(), 'repair_playlists');
    }

    public function testRepairPluginRegistryIsAdminOnly(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->repair->repair($this->makeInstance(), $this->makeCustomer(), 'repair_plugin_registry');
    }

    public function testRepairAutoDjIsAdminOnly(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->repair->repair($this->makeInstance(), $this->makeCustomer(), 'repair_autodj');
    }

    public function testRepairYoutubeIsAdminOnly(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->repair->repair($this->makeInstance(), $this->makeCustomer(), 'repair_youtube');
    }

    public function testRepairUploadDirsIsAdminOnly(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->repair->repair($this->makeInstance(), $this->makeCustomer(), 'repair_upload_dirs');
    }

    public function testClearCacheIsAllowedForCustomer(): void
    {
        $this->dispatcher->method('dispatch')->willReturn($this->makeJob());
        $result = $this->repair->repair($this->makeInstance(), $this->makeCustomer(), 'clear_cache');
        self::assertSame('clear_cache', $result['action']);
    }

    public function testRestartRuntimeIsAdminOnly(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->repair->repair($this->makeInstance(), $this->makeCustomer(), 'restart_runtime');
    }

    public function testAdminCanTriggerAllNewRepairActions(): void
    {
        $newActions = [
            'rewrite_queue',
            'repair_playlists',
            'repair_plugin_registry',
            'repair_autodj',
            'repair_youtube',
            'repair_upload_dirs',
            'clear_cache',
            'restart_runtime',
        ];
        $this->dispatcher->method('dispatch')->willReturn($this->makeJob());
        $admin = $this->makeAdmin();
        $instance = $this->makeInstance();

        foreach ($newActions as $action) {
            $result = $this->repair->repair($instance, $admin, $action);
            self::assertSame($action, $result['action'], "Admin should be able to trigger: $action");
        }
    }

    // ── Missing dependency scenarios ──────────────────────────────────────────

    public function testMissingFfmpegRepairActionIsKnown(): void
    {
        $allowed = $this->repair->allowedActionsForActor($this->makeAdmin());
        self::assertContains('ffmpeg_dependency_check', $allowed);
    }

    public function testMissingYtdlpRepairActionIsKnown(): void
    {
        $allowed = $this->repair->allowedActionsForActor($this->makeAdmin());
        self::assertContains('ytdlp_dependency_check', $allowed);
    }

    public function testMissingPulseaudioRepairActionIsKnown(): void
    {
        $allowed = $this->repair->allowedActionsForActor($this->makeAdmin());
        self::assertContains('reinit_pulseaudio', $allowed);
    }

    public function testMissingTeamspeakClientRepairActionIsKnown(): void
    {
        $allowed = $this->repair->allowedActionsForActor($this->makeAdmin());
        self::assertContains('restart_teamspeak_bridge', $allowed);
    }

    // ── Missing runtime scenarios ─────────────────────────────────────────────

    public function testMissingRuntimeBinaryRepairActionIsKnown(): void
    {
        $allowed = $this->repair->allowedActionsForActor($this->makeAdmin());
        self::assertContains('reinstall_binary', $allowed);
    }

    public function testMissingControlSocketRepairActionIsKnown(): void
    {
        $allowed = $this->repair->allowedActionsForActor($this->makeAdmin());
        self::assertContains('remove_stale_socket', $allowed);
    }

    public function testMissingConfigRepairActionIsKnown(): void
    {
        $allowed = $this->repair->allowedActionsForActor($this->makeAdmin());
        self::assertContains('rewrite_config', $allowed);
    }

    // ── Plugin repair ─────────────────────────────────────────────────────────

    public function testPluginRegistryRepairDispatchesHealthRepairJob(): void
    {
        $capturedPayload = null;
        $this->dispatcher->method('dispatch')
            ->willReturnCallback(function (mixed $node, string $type, array $payload) use (&$capturedPayload): AgentJob {
                $capturedPayload = $payload;
                return $this->makeJob();
            });

        $this->repair->repair($this->makeInstance(), $this->makeAdmin(), 'repair_plugin_registry');

        self::assertSame('repair_plugin_registry', $capturedPayload['repair_action']);
    }

    // ── Queue repair ──────────────────────────────────────────────────────────

    public function testQueueRepairDispatchesHealthRepairJob(): void
    {
        $capturedPayload = null;
        $this->dispatcher->method('dispatch')
            ->willReturnCallback(function (mixed $node, string $type, array $payload) use (&$capturedPayload): AgentJob {
                $capturedPayload = $payload;
                return $this->makeJob();
            });

        $this->repair->repair($this->makeInstance(), $this->makeAdmin(), 'rewrite_queue');

        self::assertSame('rewrite_queue', $capturedPayload['repair_action']);
    }

    // ── Upload directory repair ───────────────────────────────────────────────

    public function testUploadDirRepairDispatchesHealthRepairJob(): void
    {
        $capturedPayload = null;
        $this->dispatcher->method('dispatch')
            ->willReturnCallback(function (mixed $node, string $type, array $payload) use (&$capturedPayload): AgentJob {
                $capturedPayload = $payload;
                return $this->makeJob();
            });

        $this->repair->repair($this->makeInstance(), $this->makeAdmin(), 'repair_upload_dirs');

        self::assertSame('repair_upload_dirs', $capturedPayload['repair_action']);
    }

    // ── Parallel instances ────────────────────────────────────────────────────

    public function testParallelInstancesHaveIsolatedInstallPaths(): void
    {
        $instanceA = $this->makeInstance('mb-1-aaaa', '/opt/musicbot/instances/mb-1-aaaa');
        $instanceB = $this->makeInstance('mb-2-bbbb', '/opt/musicbot/instances/mb-2-bbbb');

        $payloads = [];
        $this->dispatcher->method('dispatch')
            ->willReturnCallback(function (mixed $node, string $type, array $payload) use (&$payloads): AgentJob {
                $payloads[] = $payload;
                return $this->makeJob();
            });

        $this->installer->install($instanceA, $this->makeAdmin());
        $this->installer->install($instanceB, $this->makeAdmin());

        self::assertCount(2, $payloads);
        self::assertNotSame($payloads[0]['install_path'], $payloads[1]['install_path']);
        self::assertNotSame($payloads[0]['service_name'], $payloads[1]['service_name']);
    }

    // ── Rollback after failed update ──────────────────────────────────────────

    public function testRollbackAfterFailedUpdateTriggersRebuild(): void
    {
        $types = [];
        $this->dispatcher->method('dispatch')
            ->willReturnCallback(function (mixed $node, string $type) use (&$types): AgentJob {
                $types[] = $type;
                return $this->makeJob();
            });

        // Simulate: update dispatched, then rebuild triggered for rollback
        $this->installer->update($this->makeInstance(), $this->makeAdmin());
        $this->installer->rebuild($this->makeInstance(), $this->makeAdmin());

        self::assertContains('musicbot.update', $types);
        self::assertContains('musicbot.repair', $types);
    }

    // ── Customer-allowed actions ──────────────────────────────────────────────

    public function testCustomerAllowedActionsContainClearCache(): void
    {
        $allowed = $this->repair->allowedActionsForActor($this->makeCustomer());
        self::assertContains('clear_cache', $allowed);
    }

    public function testCustomerAllowedActionsDoNotContainDestructiveActions(): void
    {
        $allowed = $this->repair->allowedActionsForActor($this->makeCustomer());

        self::assertNotContains('rewrite_queue', $allowed);
        self::assertNotContains('repair_plugin_registry', $allowed);
        self::assertNotContains('repair_autodj', $allowed);
        self::assertNotContains('repair_youtube', $allowed);
        self::assertNotContains('repair_upload_dirs', $allowed);
        self::assertNotContains('restart_runtime', $allowed);
        self::assertNotContains('reinstall_binary', $allowed);
        self::assertNotContains('rewrite_systemd_unit', $allowed);
        self::assertNotContains('daemon_reload', $allowed);
    }
}
