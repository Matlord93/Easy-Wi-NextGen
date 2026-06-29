<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\AgentOrchestrator\Application\AgentJobDispatcherInterface;
use App\Module\AgentOrchestrator\Domain\Entity\AgentJob;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Musicbot\Application\MusicbotRepairService;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the musicbot repair service.
 */
final class MusicbotRepairServiceTest extends TestCase
{
    private AgentJobDispatcherInterface $dispatcher;
    private AuditLogger $auditLogger;
    private MusicbotRepairService $service;

    protected function setUp(): void
    {
        $this->dispatcher = $this->createMock(AgentJobDispatcherInterface::class);
        $this->auditLogger = $this->createStub(AuditLogger::class);
        $this->service = new MusicbotRepairService($this->dispatcher, $this->auditLogger);
    }

    private function makeInstance(): MusicbotInstance
    {
        $customer = $this->createStub(User::class);
        $customer->method('getId')->willReturn(1);
        $customer->method('isAdmin')->willReturn(false);
        $customer->method('getType')->willReturn(UserType::Customer);

        $node = $this->createStub(Agent::class);
        $node->method('getId')->willReturn('node-1');

        return new MusicbotInstance($customer, $node, 'Bot', 'mb-test', '/opt/easywi/mb-test');
    }

    private function makeCustomer(): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(1);
        $user->method('isAdmin')->willReturn(false);
        $user->method('getType')->willReturn(UserType::Customer);

        return $user;
    }

    private function makeAdmin(): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(99);
        $user->method('isAdmin')->willReturn(true);
        $user->method('getType')->willReturn(UserType::Admin);

        return $user;
    }

    private function makeJob(): AgentJob
    {
        $job = $this->createStub(AgentJob::class);
        $job->method('getId')->willReturn('job-repair-1');

        return $job;
    }

    public function testRepairActionDispatchesJob(): void
    {
        $this->dispatcher->expects(self::once())
            ->method('dispatch')
            ->willReturn($this->makeJob());

        $instance = $this->makeInstance();
        $result = $this->service->repair($instance, $this->makeCustomer(), 'service_restart');

        self::assertSame('service_restart', $result['action']);
        self::assertSame('job-repair-1', $result['job_id']);
    }

    public function testCustomerCannotTriggerAdminOnlyActions(): void
    {
        $this->expectException(\RuntimeException::class);

        $instance = $this->makeInstance();
        $this->service->repair($instance, $this->makeCustomer(), 'reinstall_binary');
    }

    public function testAdminCanTriggerAnyAction(): void
    {
        $this->dispatcher->method('dispatch')->willReturn($this->makeJob());

        $instance = $this->makeInstance();
        $result = $this->service->repair($instance, $this->makeAdmin(), 'reinstall_binary');

        self::assertSame('reinstall_binary', $result['action']);
    }

    public function testUnknownActionThrowsInvalidArgument(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $instance = $this->makeInstance();
        $this->service->repair($instance, $this->makeAdmin(), 'non_existent_action');
    }

    public function testCustomerAllowedActionsDoNotIncludeSensitiveActions(): void
    {
        $customer = $this->makeCustomer();
        $allowed = $this->service->allowedActionsForActor($customer);

        self::assertNotContains('reinstall_binary', $allowed);
        self::assertNotContains('rewrite_systemd_unit', $allowed);
        self::assertNotContains('daemon_reload', $allowed);
        self::assertNotContains('remove_stale_socket', $allowed);
    }

    public function testAdminAllowedActionsIncludeAllActions(): void
    {
        $admin = $this->makeAdmin();
        $allowed = $this->service->allowedActionsForActor($admin);

        self::assertContains('service_restart', $allowed);
        self::assertContains('reinstall_binary', $allowed);
        self::assertContains('rewrite_systemd_unit', $allowed);
        self::assertContains('remove_stale_socket', $allowed);
        self::assertContains('reinit_pulseaudio', $allowed);
    }

    public function testRepairResultContainsInstanceId(): void
    {
        $this->dispatcher->method('dispatch')->willReturn($this->makeJob());

        $instance = $this->makeInstance();
        $result = $this->service->repair($instance, $this->makeAdmin(), 'force_status_refresh');

        self::assertArrayHasKey('instance_id', $result);
        self::assertArrayHasKey('dispatched_at', $result);
    }
}
