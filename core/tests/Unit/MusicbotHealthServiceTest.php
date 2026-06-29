<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Application\MusicbotHealthService;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Enum\MusicbotHealthStatus;
use App\Module\Musicbot\Domain\Enum\MusicbotInstanceStatus;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the musicbot health check service.
 */
final class MusicbotHealthServiceTest extends TestCase
{
    private MusicbotHealthService $service;

    protected function setUp(): void
    {
        $this->service = new MusicbotHealthService();
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeInstance(MusicbotInstanceStatus $status = MusicbotInstanceStatus::Running, ?array $runtimePayload = null, ?\DateTimeImmutable $updatedAt = null): MusicbotInstance
    {
        $customer = $this->createStub(User::class);
        $customer->method('getId')->willReturn(1);

        $node = $this->createStub(Agent::class);
        $node->method('getId')->willReturn('node-1');

        $instance = new MusicbotInstance($customer, $node, 'Test Bot', 'mb-test-123', '/opt/easywi/musicbot/mb-test-123');
        $instance->setStatus($status);

        if ($runtimePayload !== null) {
            $instance->setRuntimePayload($runtimePayload);
        }

        if ($updatedAt !== null) {
            $ref = new \ReflectionProperty(MusicbotInstance::class, 'updatedAt');
            $ref->setAccessible(true);
            $ref->setValue($instance, $updatedAt);
        }

        return $instance;
    }

    private function fullHealthyRuntime(): array
    {
        return [
            'running' => true,
            'runtime_ready' => true,
            'audio_backend_ready' => true,
            'connectors' => ['teamspeak' => ['status' => 'connected']],
            'teamspeak' => ['connected' => true],
            'health' => [
                'binary_present' => true,
                'config_present' => true,
                'control_socket_present' => true,
                'control_socket_responsive' => true,
                'pulseaudio_socket_present' => true,
                'pulseaudio_sink_ok' => true,
                'pulseaudio_source_ok' => true,
                'xvfb_running' => true,
                'teamspeak_client_running' => true,
                'ffmpeg_present' => true,
                'ytdlp_present' => true,
                'upload_dir_writable' => true,
                'tracks_dir_readable' => true,
            ],
            'queue_sync_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'last_agent_job_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function testHealthyInstanceIsHealthy(): void
    {
        $instance = $this->makeInstance(
            status: MusicbotInstanceStatus::Running,
            runtimePayload: $this->fullHealthyRuntime(),
            updatedAt: new \DateTimeImmutable(),
        );

        $report = $this->service->check($instance, adminView: false);

        self::assertSame(MusicbotHealthStatus::Healthy, $report['overall']);
        foreach ($report['checks'] as $check) {
            self::assertSame('healthy', $check['status'], sprintf('Check %s expected healthy', $check['name']));
        }
    }

    public function testMissingControlSocketYieldsDegraded(): void
    {
        $runtime = $this->fullHealthyRuntime();
        $runtime['health']['control_socket_present'] = false;
        $runtime['health']['control_socket_responsive'] = false;

        $instance = $this->makeInstance(runtimePayload: $runtime, updatedAt: new \DateTimeImmutable());
        $report = $this->service->check($instance);

        $socketCheck = $report['checks']['control_socket'];
        self::assertSame('degraded', $socketCheck['status']);
        self::assertTrue($socketCheck['auto_repair_available']);
        self::assertSame('service_restart', $socketCheck['repair_action']);
        self::assertContains($report['overall'], [MusicbotHealthStatus::Degraded, MusicbotHealthStatus::Failed]);
    }

    public function testMissingConfigYieldsFailed(): void
    {
        $runtime = $this->fullHealthyRuntime();
        $runtime['health']['config_present'] = false;

        $instance = $this->makeInstance(runtimePayload: $runtime, updatedAt: new \DateTimeImmutable());
        $report = $this->service->check($instance);

        $check = $report['checks']['config_present'];
        self::assertSame('failed', $check['status']);
        self::assertTrue($check['auto_repair_available']);
    }

    public function testUploadDirPermissionErrorDetected(): void
    {
        $runtime = $this->fullHealthyRuntime();
        $runtime['health']['upload_dir_writable'] = false;

        $instance = $this->makeInstance(runtimePayload: $runtime, updatedAt: new \DateTimeImmutable());
        $report = $this->service->check($instance);

        $check = $report['checks']['upload_dir'];
        self::assertSame('failed', $check['status']);
        self::assertTrue($check['auto_repair_available']);
        self::assertSame('repair_dir_permissions', $check['repair_action']);
    }

    public function testStaleSocketIsDegradedAndRepairable(): void
    {
        $runtime = $this->fullHealthyRuntime();
        $runtime['health']['control_socket_present'] = true;
        $runtime['health']['control_socket_responsive'] = false;

        $instance = $this->makeInstance(runtimePayload: $runtime, updatedAt: new \DateTimeImmutable());
        $report = $this->service->check($instance);

        $check = $report['checks']['control_socket'];
        self::assertSame('degraded', $check['status']);
        self::assertSame('remove_stale_socket', $check['repair_action']);
    }

    public function testMissingPulseaudioYieldsDegraded(): void
    {
        $runtime = $this->fullHealthyRuntime();
        $runtime['health']['pulseaudio_socket_present'] = false;

        $instance = $this->makeInstance(runtimePayload: $runtime, updatedAt: new \DateTimeImmutable());
        $report = $this->service->check($instance);

        $check = $report['checks']['pulseaudio_socket'];
        self::assertSame('degraded', $check['status']);
    }

    public function testNoRuntimePayloadYieldsUnknownChecks(): void
    {
        $instance = $this->makeInstance(runtimePayload: null);
        $report = $this->service->check($instance);

        self::assertSame(MusicbotHealthStatus::Unknown, $report['overall']);
        $binaryCheck = $report['checks']['binary_present'];
        self::assertSame('unknown', $binaryCheck['status']);
    }

    public function testAdminViewContainsSensitiveDetails(): void
    {
        $runtime = $this->fullHealthyRuntime();
        $runtime['health']['tracks_dir_readable'] = false;

        $instance = $this->makeInstance(runtimePayload: $runtime, updatedAt: new \DateTimeImmutable());

        $adminReport = $this->service->check($instance, adminView: true);
        $customerReport = $this->service->check($instance, adminView: false);

        // Both should have the check; customer view should not expose path details
        self::assertArrayHasKey('tracks_dir', $adminReport['checks']);
        self::assertArrayHasKey('tracks_dir', $customerReport['checks']);
    }

    public function testCustomerViewDoesNotExposeSecretKeys(): void
    {
        $runtime = $this->fullHealthyRuntime();
        $instance = $this->makeInstance(runtimePayload: $runtime, updatedAt: new \DateTimeImmutable());

        $report = $this->service->check($instance, adminView: false);

        foreach ($report['checks'] as $check) {
            foreach (array_keys($check['details'] ?? []) as $key) {
                self::assertStringNotContainsStringIgnoringCase('password', (string) $key);
                self::assertStringNotContainsStringIgnoringCase('secret', (string) $key);
                self::assertStringNotContainsStringIgnoringCase('token', (string) $key);
            }
        }
    }

    public function testStaleRuntimeStatusYieldsWarning(): void
    {
        $runtime = $this->fullHealthyRuntime();
        // Make queue sync and agent job fresh so only runtime_status_fresh triggers
        $instance = $this->makeInstance(
            runtimePayload: $runtime,
            updatedAt: new \DateTimeImmutable('-600 seconds'),
        );

        $report = $this->service->check($instance);

        $check = $report['checks']['runtime_status_fresh'];
        self::assertSame('warning', $check['status']);
        self::assertTrue($check['auto_repair_available']);
    }

    public function testCheckedAtIsIncluded(): void
    {
        $instance = $this->makeInstance();
        $report = $this->service->check($instance);

        self::assertArrayHasKey('checked_at', $report);
        self::assertNotEmpty($report['checked_at']);
    }

    public function testOverallAggregatesWorstStatus(): void
    {
        $runtime = $this->fullHealthyRuntime();
        $runtime['health']['config_present'] = false; // => failed
        $runtime['health']['control_socket_present'] = false; // => degraded

        $instance = $this->makeInstance(runtimePayload: $runtime, updatedAt: new \DateTimeImmutable());
        $report = $this->service->check($instance);

        self::assertSame(MusicbotHealthStatus::Failed, $report['overall']);
    }
}
