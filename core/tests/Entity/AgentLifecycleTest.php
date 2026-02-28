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
}
