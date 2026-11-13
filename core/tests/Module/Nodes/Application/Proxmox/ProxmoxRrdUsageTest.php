<?php

declare(strict_types=1);

namespace App\Tests\Module\Nodes\Application\Proxmox;

use App\Module\Nodes\Application\Proxmox\ProxmoxRrdUsage;
use App\Module\Nodes\Domain\Proxmox\ProxmoxConnection;
use App\Tests\Support\ProxmoxFixture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class ProxmoxRrdUsageTest extends TestCase
{
    public function testRrdRatesAreNotDifferentiatedAgainAndMissingValuesStayUnknown(): void
    {
        $now = time();
        $sample = ProxmoxRrdUsage::latest([['time' => $now - 65], ['time' => $now - 5, 'netin' => 100.5, 'netout' => 0, 'diskread' => null, 'diskwrite' => -1, 'private' => 'secret']], $now);
        self::assertSame(100.5, $sample['net_rx_bytes_per_second']);
        self::assertSame(0.0, $sample['net_tx_bytes_per_second']);
        self::assertArrayNotHasKey('disk_read_bytes_per_second', $sample);
        self::assertArrayNotHasKey('disk_write_bytes_per_second', $sample);
        self::assertSame(60, $sample['io_interval_seconds']);
        self::assertArrayNotHasKey('private', $sample);
    }

    public function testInvalidOldFutureOrIncompleteIntervalsAreUnknown(): void
    {
        $now = time();
        foreach ([[], [['time' => $now, 'netin' => 10]], [['time' => $now - 360, 'netin' => 10], ['time' => $now - 300, 'netin' => 20]], [['time' => $now + 60, 'netin' => 10]], [['time' => $now - 65], ['time' => $now - 5, 'netin' => NAN, 'diskwrite' => INF]]] as $rows) {
            self::assertSame([], ProxmoxRrdUsage::latest($rows, $now));
        }
    }

    public function testCacheCannotDisplayAnIntervalFromBeforeTheCurrentVmRun(): void
    {
        $fixture = new ProxmoxFixture();
        $reader = new ProxmoxRrdUsage($fixture->client(), new ArrayAdapter());
        $connection = new ProxmoxConnection('https://pve.example.test', 'panel@pve!test', 'synthetic');
        $sample = $reader->forGuest($connection, 'pve-a', 'qemu', 101, ['status' => 'running', 'uptime' => 600]);
        self::assertSame('proxmox_rrd', $sample['io_source']);
        self::assertSame([], $reader->forGuest($connection, 'pve-a', 'qemu', 101, ['status' => 'running', 'uptime' => 30]));
        self::assertSame([], $reader->forGuest($connection, 'pve-a', 'qemu', 101, ['status' => 'stopped', 'uptime' => 600]));
        self::assertCount(1, $fixture->requests);
        self::assertSame(['timeframe' => 'hour', 'cf' => 'AVERAGE'], $fixture->requests[0]['query']);
        self::assertSame([], $fixture->writes());
    }

    public function testMissingRrdPermissionIsAnOptionalMetricFailure(): void
    {
        $fixture = new ProxmoxFixture();
        $fixture->rrdFail = true;
        $reader = new ProxmoxRrdUsage($fixture->client(), new ArrayAdapter());
        self::assertSame([], $reader->forGuest(new ProxmoxConnection('https://pve.example.test', 'panel@pve!test', 'synthetic'), 'pve-a', 'qemu', 101, ['status' => 'running', 'uptime' => 600]));
    }
}
