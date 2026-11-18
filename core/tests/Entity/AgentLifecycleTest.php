<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Module\Core\Domain\Entity\Agent;
use PHPUnit\Framework\TestCase;

final class AgentLifecycleTest extends TestCase
{
    public function testLifecycleStateMachineTransitions(): void
    {
        $agent = new Agent('agent-lifecycle', ['key_id' => 'k', 'nonce' => 'n', 'ciphertext' => 'c'], 'Lifecycle');

        self::assertSame(Agent::STATUS_REGISTERED, $agent->resolveLifecycleStatus());

        $agent->recordHeartbeat([], '1.0.0', '127.0.0.1');
        self::assertSame(Agent::STATUS_ACTIVE, $agent->resolveLifecycleStatus(new \DateTimeImmutable()));

        $offlineAt = (new \DateTimeImmutable())->modify('+10 minutes');
        self::assertSame(Agent::STATUS_OFFLINE, $agent->resolveLifecycleStatus($offlineAt));

        $agent->markDecommissioned();
        self::assertSame(Agent::STATUS_DECOMMISSIONED, $agent->resolveLifecycleStatus());
    }

    public function testHeartbeatDoesNotReactivateDecommissionedAgent(): void
    {
        $agent = new Agent('agent-decom', ['key_id' => 'k', 'nonce' => 'n', 'ciphertext' => 'c'], 'Lifecycle');
        $agent->markDecommissioned();

        $agent->recordHeartbeat(['cpu' => 12], '2.0.0', '10.0.0.2');

        self::assertSame(Agent::STATUS_DECOMMISSIONED, $agent->getStatus());
        self::assertNull($agent->getLastHeartbeatAt());
        self::assertNull($agent->getLastHeartbeatVersion());
    }

    public function testHeartbeatDoesNotAssignAgentReportedRoles(): void
    {
        $agent = new Agent('agent-roles-empty', ['key_id' => 'k', 'nonce' => 'n', 'ciphertext' => 'c'], 'Roles');

        $agent->recordHeartbeat(['cpu' => 12], '2.0.0', '10.0.0.2', ['TS3']);

        self::assertSame([], $agent->getRoles());
    }

    public function testHeartbeatDoesNotOverwritePanelManagedRoles(): void
    {
        $agent = new Agent('agent-roles-managed', ['key_id' => 'k', 'nonce' => 'n', 'ciphertext' => 'c'], 'Roles');
        $agent->setRoles(['Game']);

        $agent->recordHeartbeat(['cpu' => 12], '2.0.0', '10.0.0.2', ['TS3']);

        self::assertSame(['Game'], $agent->getRoles());
    }

    public function testHeartbeatPreservesDiskMeasurementsAndPanelSettings(): void
    {
        $agent = new Agent('disk-metadata', ['key_id' => 'k', 'nonce' => 'n', 'ciphertext' => 'c']);
        $protection = new \App\Module\Core\Application\NodeDiskProtectionService();
        $agent->setMetadata(['instance_base_dirs' => ['/srv/games'], 'mail_host' => 'mail.example.test', 'core_access_mode' => 'public', 'hostname' => 'old', 'php_versions' => ['8.4']]);
        $measuredAt = new \DateTimeImmutable('-1 minute');
        $protection->updateDiskStat($agent, 1024, 1.0, $measuredAt);

        $agent->recordHeartbeat([], '1.0', '127.0.0.1', [], [
            'hostname' => 'new', 'ts6_supported' => false,
            'disk_stat' => ['free_bytes' => 999999, 'free_percent' => 100],
            'disk_protection' => ['active' => false], 'mail_host' => 'reported.example.test',
        ]);
        self::assertSame(1024, $protection->getDiskStat($agent)['free_bytes']);
        self::assertEquals($measuredAt->format(DATE_RFC3339), $protection->getDiskStat($agent)['checked_at']->format(DATE_RFC3339));
        self::assertTrue($protection->isProtectionActive($agent, new \DateTimeImmutable()));
        self::assertSame('mail.example.test', $agent->getMetadata()['mail_host']);
        self::assertSame(['/srv/games'], $agent->getMetadata()['instance_base_dirs']);
        self::assertSame('public', $agent->getMetadata()['core_access_mode']);
        self::assertSame('new', $agent->getMetadata()['hostname']);
        self::assertArrayNotHasKey('php_versions', $agent->getMetadata());
        self::assertFalse($agent->getMetadata()['ts6_supported']);
        $agent->recordHeartbeat([], '1.0', '127.0.0.1', [], []);
        self::assertSame(1024, $protection->getDiskStat($agent)['free_bytes']);
    }

    public function testHeartbeatCannotCreatePanelManagedDiskMeasurements(): void
    {
        $agent = new Agent('unmeasured-disk', ['key_id' => 'k', 'nonce' => 'n', 'ciphertext' => 'c']);
        $agent->recordHeartbeat([], '1.0', '127.0.0.1', [], ['disk_stat' => ['free_bytes' => 0], 'disk_protection' => ['active' => false]]);
        self::assertNull($agent->getMetadata());
    }
}
