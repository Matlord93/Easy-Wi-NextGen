<?php

declare(strict_types=1);

namespace App\Tests\Module\Nodes;

use App\Module\Nodes\Application\InfrastructureUsage;
use PHPUnit\Framework\TestCase;

final class InfrastructureUsageTest extends TestCase
{
    public function testMissingOrInvalidReadingsAreNotDisplayedAsZeroOrExposed(): void
    {
        $usage = (new InfrastructureUsage())->normalize(['cpu_percent' => 'NaN', 'memory_used_bytes' => -1, 'token' => 'private', 'observed_at' => 'not a timestamp']);
        self::assertNull($usage['cpu_percent']);
        self::assertNull((new InfrastructureUsage())->normalize(['memory_used_bytes' => 1e308, 'memory_total_bytes' => 0.0001])['memory_percent']);
        self::assertNull($usage['memory_percent']);
        self::assertTrue($usage['stale']);
        self::assertArrayNotHasKey('token', $usage);
    }

    public function testProxmoxFractionsAreConvertedAndZeroIsAValidReading(): void
    {
        $usage = (new InfrastructureUsage())->proxmox(['cpu' => .375, 'mem' => 0, 'maxmem' => 1024, 'disk' => 25, 'maxdisk' => 100]);
        self::assertSame(37.5, $usage['cpu_percent']);
        self::assertSame(0.0, $usage['memory_percent']);
        self::assertSame(25.0, $usage['disk_percent']);
        self::assertFalse($usage['stale']);
    }

    public function testQemuStatusDiskZeroDoesNotClaimAnEmptyGuestFilesystem(): void
    {
        $usage = new InfrastructureUsage();
        foreach (['qemu', null] as $type) {
            $values = $usage->proxmox(['vmid' => 101, 'type' => $type, 'disk' => 0, 'maxdisk' => 40 * 1073741824]);
            self::assertNull($values['disk_used_bytes']);
            self::assertNull($values['disk_percent']);
            self::assertSame(40.0 * 1073741824, $values['disk_total_bytes']);
        }
        self::assertSame(25.0, $usage->proxmox(['vmid' => 102, 'type' => 'lxc', 'disk' => 25, 'maxdisk' => 100])['disk_percent']);
    }

    public function testRateSamplesRequireFreshTimestampKnownSourceAndInterval(): void
    {
        $input = ['io_observed_at' => date(DATE_ATOM), 'io_source' => 'counter_delta', 'io_interval_seconds' => 20, 'net_rx_bytes_per_second' => 0, 'net_tx_bytes_per_second' => 2048, 'disk_read_bytes_per_second' => -1, 'disk_write_bytes_per_second' => INF];
        $usage = (new InfrastructureUsage())->normalize($input);
        self::assertSame(0.0, $usage['net_rx_bytes_per_second']);
        self::assertSame(2048.0, $usage['net_tx_bytes_per_second']);
        self::assertNull($usage['disk_read_bytes_per_second']);
        self::assertNull($usage['disk_write_bytes_per_second']);
        foreach ([['io_observed_at' => date(DATE_ATOM, time() - 300)], ['io_source' => 'untrusted'], ['io_interval_seconds' => 0]] as $override) {
            self::assertNull((new InfrastructureUsage())->normalize(array_replace($input, $override))['net_tx_bytes_per_second']);
        }
    }

    public function testRelativeAndFutureTimestampsCannotClaimFreshMeasurements(): void
    {
        foreach (['tomorrow', '', '2026-02-30T12:00:00Z', date(DATE_ATOM, time() + 3600)] as $stamp) {
            self::assertTrue((new InfrastructureUsage())->normalize(['observed_at' => $stamp])['stale']);
        }
    }

    public function testProxmoxStatusCountersCannotMasqueradeAsRates(): void
    {
        $usage = (new InfrastructureUsage())->proxmox(['netin' => 99999999, 'diskread' => 88888888]);
        self::assertNull($usage['net_rx_bytes_per_second']);
        self::assertNull($usage['disk_read_bytes_per_second']);
    }

    public function testMetricSourcesAreExplicitAndUnknownSourceTextIsNotForwarded(): void
    {
        $usage = new InfrastructureUsage();
        self::assertSame('proxmox_status', $usage->proxmox(['vmid' => 101])['load_source']);
        self::assertSame('proxmox_cluster', $usage->proxmox(['node' => 'pve-a'])['load_source']);
        self::assertNull($usage->normalize(['load_source' => 'private arbitrary provider text'])['load_source']);
        self::assertSame('agent_heartbeat', $usage->normalize(['load_source' => 'agent_heartbeat'])['load_source']);
    }
}
