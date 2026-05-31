<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\SinusbotNode;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that SinusbotNode carries the instanceMode that SinusbotNodeService
 * reads and forwards to the agent install payload.
 *
 * Full end-to-end payload capture is not feasible in unit tests because
 * AgentJobDispatcher and its entire dependency chain are declared final.
 * The service code itself is trivially reviewed: it does
 *   'instance_mode' => $node->getInstanceMode()
 * so these tests validate the entity getters that feed that field.
 */
final class SinusbotNodeServiceInstallPayloadTest extends TestCase
{
    private function makeNode(string $installPath, string $instanceRoot, string $instanceMode): SinusbotNode
    {
        $agent = $this->createMock(Agent::class);
        $node = new SinusbotNode(
            'Test',
            $agent,
            'http://agent.local',
            'encrypted-token',
            'https://example.test/sinusbot.tar.bz2',
            $installPath,
            $instanceRoot,
        );
        $node->setInstanceMode($instanceMode);
        return $node;
    }

    public function testSoloNodeExposesCorrectModeForPayload(): void
    {
        $node = $this->makeNode('/opt/sinusbot', '', 'solo');

        $this->assertSame('solo', $node->getInstanceMode());
        $this->assertSame('/opt/sinusbot', $node->getInstallPath());
        $this->assertSame('', $node->getInstanceRoot());
    }

    public function testMultiNodeExposesCorrectModeAndRootForPayload(): void
    {
        $node = $this->makeNode('/opt/sinusbot', '/var/lib/sinusbot-instances', 'multi');

        $this->assertSame('multi', $node->getInstanceMode());
        $this->assertSame('/var/lib/sinusbot-instances', $node->getInstanceRoot());
        $this->assertSame('/opt/sinusbot', $node->getInstallPath());
    }

    public function testDefaultNodeModeIsMultiMatchingMigrationDefault(): void
    {
        $agent = $this->createMock(Agent::class);
        $node = new SinusbotNode(
            'Default',
            $agent,
            'http://agent.local',
            'token',
            'https://example.test/sinusbot.tar.bz2',
            '/opt/sinusbot',
            '',
        );

        $this->assertSame(
            'multi',
            $node->getInstanceMode(),
            'Entity PHP default must match the DB migration DEFAULT so existing rows are treated as multi',
        );
    }
}
