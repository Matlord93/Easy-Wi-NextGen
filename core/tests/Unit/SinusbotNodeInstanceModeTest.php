<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\SinusbotNode;
use App\Module\Core\Dto\Sinusbot\SinusbotNodeDto;
use PHPUnit\Framework\TestCase;

final class SinusbotNodeInstanceModeTest extends TestCase
{
    private function makeNode(string $mode = 'multi'): SinusbotNode
    {
        $agent = $this->createMock(Agent::class);
        $node = new SinusbotNode(
            'Test Node',
            $agent,
            'http://agent.local',
            'encrypted-token',
            'https://example.test/sinusbot.tar.bz2',
            '/opt/sinusbot',
            '/var/lib/sinusbot-instances',
        );
        $node->setInstanceMode($mode);
        return $node;
    }

    public function testDefaultDtoModeIsMulti(): void
    {
        $dto = new SinusbotNodeDto();
        $this->assertSame('multi', $dto->instanceMode);
    }

    public function testEntityDefaultModeIsMulti(): void
    {
        $agent = $this->createMock(Agent::class);
        $node = new SinusbotNode(
            'Test',
            $agent,
            'http://agent.local',
            'token',
            'https://example.test/sinusbot.tar.bz2',
            '/opt/sinusbot',
            '',
        );
        $this->assertSame('multi', $node->getInstanceMode());
    }

    public function testSetInstanceModeSolo(): void
    {
        $node = $this->makeNode('solo');
        $this->assertSame('solo', $node->getInstanceMode());
    }

    public function testSetInstanceModeMulti(): void
    {
        $node = $this->makeNode('multi');
        $this->assertSame('multi', $node->getInstanceMode());
    }

    public function testModeCanBeChangedFromSoloToMulti(): void
    {
        $node = $this->makeNode('solo');
        $this->assertSame('solo', $node->getInstanceMode());

        $node->setInstanceMode('multi');
        $this->assertSame('multi', $node->getInstanceMode());
    }
}
