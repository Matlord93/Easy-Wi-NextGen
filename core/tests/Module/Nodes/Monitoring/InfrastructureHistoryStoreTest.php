<?php

declare(strict_types=1);

namespace App\Tests\Module\Nodes\Monitoring;

use App\Module\Nodes\Application\InfrastructureUsage;
use App\Module\Nodes\Application\Monitoring\InfrastructureHistoryStore;
use App\Module\Nodes\Application\Monitoring\InfrastructureTarget;
use App\Module\Nodes\Command\InfrastructureArchiveCommand;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class InfrastructureHistoryStoreTest extends TestCase
{
    private Connection $database;

    private function store(): InfrastructureHistoryStore
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE infrastructure_samples (resource_key TEXT, stream TEXT, bucket INTEGER, observed_at INTEGER, payload TEXT, PRIMARY KEY(resource_key, stream, bucket))');
        $db->executeStatement('CREATE TABLE infrastructure_rollups (resource_key TEXT, stream TEXT, bucket INTEGER, payload TEXT, PRIMARY KEY(resource_key, stream, bucket))');
        $this->database = $db;

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
        self::assertSame(2, $store->prune(1, new \DateTimeImmutable('+2 days')));
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

    public function testArchivesPreserveWeightedStatisticsAndExactOwnershipBoundaries(): void
    {
        $store = $this->store();
        $target = $this->target();
        $base = intdiv(time() - 40 * 86400, 7200) * 7200;
        foreach ([[300, 0, null], [1200, 100, null], [1800, 40, 10], [4200, 20, 30], [7500, 80, null]] as [$offset, $cpu, $memory]) {
            $this->seed($target, 'load', $base + $offset, ['cpu_percent' => $cpu, 'memory_percent' => $memory, 'source' => 'proxmox_status']);
        }
        foreach ([[1400, 1024, 60], [4500, 0, 20]] as [$offset, $rate, $interval]) {
            $this->seed($target, 'io', $base + $offset, ['net_rx_bytes_per_second' => $rate, 'interval_seconds' => $interval, 'source' => 'counter_delta']);
        }
        $this->seed($this->target('assignment:1', 'foreign-cluster'), 'load', $base + 1200, ['cpu_percent' => 99]);
        $from = $base + 900;
        $to = $base + 40 * 86400;
        $before = $store->history($target, $from, $to);
        self::assertSame(6, array_sum(array_column($before['points'], 'samples')));
        // Small batches must be resumable, including when other resources have data.
        self::assertSame(['created' => 1, 'pending' => true], $store->backfill(1));
        self::assertSame(['created' => 5, 'pending' => true], $store->backfill(100));
        self::assertSame(['created' => 0, 'pending' => false], $store->backfill(100));
        $after = $store->history($target, $from, $to);
        self::assertEquals($before, $after);
        self::assertSame(8, (int) $this->database->fetchOne('SELECT COUNT(*) FROM infrastructure_samples'));
        $load = array_values(array_filter($after['points'], static fn (array $p): bool => 'load' === $p['stream']))[0];
        self::assertSame(3, $load['samples']);
        self::assertEqualsWithDelta(160 / 3, $load['metrics']['cpu_percent']['average'], 0.000001);
        self::assertSame(3, $load['metrics']['cpu_percent']['count']);
        self::assertEquals(20, $load['metrics']['cpu_percent']['min']);
        self::assertEquals(100, $load['metrics']['cpu_percent']['max']);
        self::assertEquals(20, $load['metrics']['memory_percent']['average']);
        self::assertSame(2, $load['metrics']['memory_percent']['count']);
        $io = $after['points'][0];
        self::assertSame('io', $io['stream']);
        self::assertEquals(512, $io['metrics']['net_rx_bytes_per_second']['average']);
        self::assertEquals(0, $io['metrics']['net_rx_bytes_per_second']['min']);
        self::assertEquals(20, $io['interval_min_seconds']);
        self::assertEquals(60, $io['interval_max_seconds']);
        $restricted = new InfrastructureTarget('proxmox', 'one', 'qemu', '101', 'assignment:1', 'Test VM', '/admin/proxmox', null, null, $base + 1500);
        $visible = $store->history($restricted, $from, $to)['points'];
        self::assertSame(4, array_sum(array_column($visible, 'samples')));
        $firstLoad = array_values(array_filter($visible, static fn (array $p): bool => 'load' === $p['stream']))[0];
        self::assertEquals(30, $firstLoad['metrics']['cpu_percent']['average']);
        self::assertEquals(0, $visible[0]['metrics']['net_rx_bytes_per_second']['average']);
    }

    public function testDuplicateMinuteDoesNotIncrementHourlyStatistics(): void
    {
        $store = $this->store();
        $at = time() - 2;
        $payload = ['cpu_percent' => 25, 'observed_at' => gmdate(DATE_ATOM, $at)];
        self::assertSame(1, $store->record($this->target(), $payload));
        self::assertSame(0, $store->record($this->target(), $payload));
        $archive = json_decode($this->database->fetchOne('SELECT payload FROM infrastructure_rollups'), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame(1, $archive['samples']);
        self::assertSame(1, $archive['metrics']['cpu_percent']['count']);
    }

    public function testHourlyWriteFailureAlsoRollsBackRawSample(): void
    {
        $store = $this->store();
        $this->database->executeStatement('DROP TABLE infrastructure_rollups');
        try {
            $store->record($this->target(), ['cpu_percent' => 25, 'observed_at' => gmdate(DATE_ATOM, time() - 1)]);
            self::fail('Missing archive table must fail the transaction.');
        } catch (\Doctrine\DBAL\Exception\TableNotFoundException) {
            self::assertSame(0, (int) $this->database->fetchOne('SELECT COUNT(*) FROM infrastructure_samples'));
            self::assertFalse($this->database->isTransactionActive());
        }
    }

    public function testPartialLastHourNeverIncludesObservationsAfterTheRequestedEnd(): void
    {
        $store = $this->store();
        $base = intdiv(time() - 4 * 86400, 3600) * 3600;
        $end = $base + 2 * 86400 + 1200;
        $this->seed($this->target(), 'load', $end - 300, ['cpu_percent' => 0]);
        $this->seed($this->target(), 'load', $end, ['cpu_percent' => 100]);
        $this->seed($this->target(), 'load', $end + 300, ['cpu_percent' => 90]);
        $store->backfill();
        $history = $store->history($this->target(), $base, $end);
        self::assertCount(1, $history['points']);
        self::assertSame(1, $history['points'][0]['samples']);
        self::assertSame(0.0, $history['points'][0]['metrics']['cpu_percent']['average']);
        self::assertSame($end - 300, $history['points'][0]['last_observed_at']);
    }

    public function testArchiveCommandResumesBoundedBatchesWithoutDeletingRawData(): void
    {
        $store = $this->store();
        $base = intdiv(time() - 86400, 3600) * 3600;
        foreach ([0, 3600, 7200] as $offset) {
            $this->seed($this->target(), 'load', $base + $offset, ['cpu_percent' => 20]);
        }
        $command = new CommandTester(new InfrastructureArchiveCommand($store));
        self::assertSame(0, $command->execute(['--batch-size' => 1, '--batches' => 1]));
        self::assertSame(['created' => 1, 'pending' => true], json_decode($command->getDisplay(), true, 32, JSON_THROW_ON_ERROR));
        self::assertSame(1, (int) $this->database->fetchOne('SELECT COUNT(*) FROM infrastructure_rollups'));
        self::assertSame(0, $command->execute(['--batch-size' => 1, '--batches' => 3]));
        self::assertSame(['created' => 2, 'pending' => false], json_decode($command->getDisplay(), true, 32, JSON_THROW_ON_ERROR));
        self::assertSame(3, (int) $this->database->fetchOne('SELECT COUNT(*) FROM infrastructure_samples'));
        self::assertSame(3, (int) $this->database->fetchOne('SELECT COUNT(*) FROM infrastructure_rollups'));
    }

    private function seed(InfrastructureTarget $target, string $stream, int $observed, array $payload): void
    {
        $this->database->insert('infrastructure_samples', ['resource_key' => $target->key, 'stream' => $stream, 'bucket' => intdiv($observed, 60) * 60, 'observed_at' => $observed, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
    }
}
