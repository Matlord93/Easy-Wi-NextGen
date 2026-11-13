<?php

declare(strict_types=1);

namespace App\Tests\Module\Nodes\Monitoring;

use App\Module\Nodes\Application\InfrastructureUsage;
use App\Module\Nodes\Application\Monitoring\InfrastructureHistoryStore;
use App\Module\Nodes\Application\Monitoring\InfrastructureTarget;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class InfrastructureHistoryStoreTest extends TestCase
{
    private function store(): InfrastructureHistoryStore
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE infrastructure_samples (resource_key TEXT, stream TEXT, bucket INTEGER, observed_at INTEGER, payload TEXT, PRIMARY KEY(resource_key, stream, bucket))');

        return new InfrastructureHistoryStore($db, new InfrastructureUsage());
    }

    private function target(string $epoch = 'assignment:1', string $connection = 'one'): InfrastructureTarget
    {
        return new InfrastructureTarget('proxmox', $connection, 'qemu', '101', $epoch, 'Test VM', '/admin/proxmox');
    }

    public function testMinuteDeduplicationSourceTimesAndKnownZeroArePreserved(): void
    {
        $store = $this->store();
        $target = $this->target();
        $now = time() - 5;
        $data = ['cpu_percent' => 0, 'memory_used_bytes' => 10, 'memory_total_bytes' => 100, 'observed_at' => gmdate(DATE_ATOM, $now), 'load_source' => 'proxmox_status', 'io_observed_at' => gmdate(DATE_ATOM, $now - 60), 'io_source' => 'proxmox_rrd', 'io_interval_seconds' => 60, 'net_rx_bytes_per_second' => 0, 'secret' => 'never store'];
        self::assertSame(2, $store->record($target, $data));
        self::assertSame(0, $store->record($target, $data));
        $result = $store->history($target, $now - 120, $now + 1);
        self::assertCount(2, $result['points']);
        self::assertSame('io', $result['points'][0]['stream']);
        self::assertSame($now - 60, $result['points'][0]['first_observed_at']);
        self::assertSame(0.0, $result['points'][0]['metrics']['net_rx_bytes_per_second']['average']);
        self::assertSame(0.0, $result['points'][1]['metrics']['cpu_percent']['average']);
        self::assertArrayNotHasKey('secret', $result['points'][1]['metrics']);
    }

    public function testAssignmentEpochAndClusterIdentityCannotShareHistory(): void
    {
        $store = $this->store();
        $now = time() - 1;
        $store->record($this->target(), ['cpu_percent' => 40, 'load_source' => 'proxmox_status', 'observed_at' => gmdate(DATE_ATOM, $now)]);
        self::assertSame([], $store->history($this->target('assignment:2'), $now - 120, $now + 1)['points']);
        self::assertSame([], $store->history($this->target('assignment:1', 'other-cluster'), $now - 120, $now + 1)['points']);
    }

    public function testStaleMissingAndInvalidMetricsAreNotManufactured(): void
    {
        $store = $this->store();
        $target = $this->target();
        self::assertSame(0, $store->record($target, ['cpu_percent' => 10, 'observed_at' => gmdate(DATE_ATOM, time() - 1000)]));
        self::assertSame(0, $store->record($target, ['observed_at' => gmdate(DATE_ATOM)]));
        self::assertSame(0, $store->record($target, ['cpu_percent' => INF, 'observed_at' => gmdate(DATE_ATOM)]));
    }

    public function testReassignmentDoesNotImportAnOldHeartbeatOrAnOverlappingIoInterval(): void
    {
        $store = $this->store();
        $now = time() - 1;
        $target = new InfrastructureTarget('kvm', 'node', 'vm', 'uuid', 'assignment:2', 'VM', '/admin/kvm', null, null, $now - 30);
        $old = ['cpu_percent' => 99, 'observed_at' => gmdate(DATE_ATOM, $now - 60), 'load_source' => 'agent_heartbeat'];
        self::assertSame(0, $store->record($target, $old));
        $new = ['cpu_percent' => 20, 'observed_at' => gmdate(DATE_ATOM, $now), 'load_source' => 'agent_heartbeat', 'io_observed_at' => gmdate(DATE_ATOM, $now), 'io_source' => 'counter_delta', 'io_interval_seconds' => 60, 'net_rx_bytes_per_second' => 10];
        self::assertSame(1, $store->record($target, $new));
        $new['io_interval_seconds'] = 10;
        self::assertSame(1, $store->record($target, $new));
        self::assertCount(2, $store->history($target, $now - 120, $now + 1)['points']);
    }

    public function testRetentionAndTimeWindowDoNotDeleteRecentSamples(): void
    {
        $store = $this->store();
        $target = $this->target();
        $now = time() - 1;
        $store->record($target, ['cpu_percent' => 5, 'observed_at' => gmdate(DATE_ATOM, $now)]);
        self::assertSame(1, $store->prune(1, new \DateTimeImmutable('+2 days')));
        self::assertSame([], $store->history($target, $now - 60, $now + 1)['points']);
        $store->record($target, ['cpu_percent' => 6, 'observed_at' => gmdate(DATE_ATOM, $now)]);
        self::assertSame(0, $store->prune(30));
        self::assertCount(1, $store->history($target, $now - 60, $now + 1)['points']);
    }

    public function testInvalidRangesAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->store()->history($this->target(), time(), time() - 1);
    }
}
