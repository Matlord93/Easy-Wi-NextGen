<?php

declare(strict_types=1);

namespace App\Tests\Module\Unifi;

use App\Module\Unifi\Application\UnifiRule;
use App\Module\Unifi\Application\UnifiRuleDiff;
use PHPUnit\Framework\TestCase;

final class UnifiRuleDiffTest extends TestCase
{
    public function testDiffComputesCreateUpdateDelete(): void
    {
        $diff = new UnifiRuleDiff();

        $desired = [
            'PANEL-1-tcp-25565' => new UnifiRule('PANEL-1-tcp-25565', 'tcp', 25565, '10.0.0.10', 25565, true, 'auto'),
            'MANUAL-2-udp-27015' => new UnifiRule('MANUAL-2-udp-27015', 'udp', 27015, '10.0.0.11', 27015, true, 'manual'),
        ];

        $current = [
            'PANEL-1-tcp-25565' => [
                'id' => 'rule-1',
                'port' => 25565,
                'target_port' => 25565,
                'target_ip' => '10.0.0.20',
                'protocol' => 'tcp',
                'enabled' => true,
            ],
            'PANEL-legacy' => [
                'id' => 'rule-legacy',
                'port' => 27016,
                'target_port' => 27016,
                'target_ip' => '10.0.0.12',
                'protocol' => 'udp',
                'enabled' => true,
            ],
        ];

        $result = $diff->diff($desired, $current);

        self::assertCount(1, $result['create']);
        self::assertSame('MANUAL-2-udp-27015', $result['create'][0]->getName());

        self::assertCount(1, $result['update']);
        self::assertSame('rule-1', $result['update'][0]['currentId']);

        self::assertCount(1, $result['delete']);
        self::assertSame('PANEL-legacy', $result['delete'][0]['name']);
    }
}
