<?php

declare(strict_types=1);

namespace App\Tests\Module\Nodes\Application;

use App\Module\Nodes\Application\VmFilesystemUsage;
use PHPUnit\Framework\TestCase;

final class VmFilesystemUsageTest extends TestCase
{
    public function testReportedErofsRootIsIncludedAndExt4ZramIsExcluded(): void
    {
        $rows = json_decode(file_get_contents(dirname(__DIR__, 3).'/Fixtures/guest-filesystem-erofs-zram.json'), true, 32, JSON_THROW_ON_ERROR);
        $report = VmFilesystemUsage::fromQga($rows);
        self::assertSame(['state' => 'available', 'used_bytes' => 17901221888.0, 'total_bytes' => 129377415168.0, 'filesystems' => 4, 'partial' => false], $report);
    }

    public function testRamDeviceAliasesAreExcludedWithoutFilteringPersistentTmpMounts(): void
    {
        $filesystem = ['name' => 'sda1', 'mountpoint' => '/tmp', 'type' => 'ext4', 'total-bytes' => 1000, 'used-bytes' => 250];
        foreach (['zram0', '/dev/zram12', 'ram0', '/dev/ram12'] as $device) {
            $report = VmFilesystemUsage::fromQga([$filesystem, array_replace($filesystem, ['name' => $device])]);
            self::assertSame(1, $report['filesystems']);
            self::assertFalse($report['partial']);
            self::assertSame(250.0, $report['used_bytes']);
            $report = VmFilesystemUsage::fromQga([$filesystem, array_replace($filesystem, ['name' => 'alias', 'disk' => [['dev' => $device]]])]);
            self::assertSame(1, $report['filesystems']);
            self::assertSame(1000.0, $report['total_bytes']);
        }
    }

    public function testLinuxMountsAreCountedOnceAndRamNetworkAndOpticalMountsAreExcluded(): void
    {
        $root = ['name' => 'sda1', 'mountpoint' => '/', 'type' => 'ext4', 'total-bytes' => 1000, 'used-bytes' => 250, 'disk' => [['dev' => '/private/device']]];
        $report = VmFilesystemUsage::fromQga([$root, array_replace($root, ['mountpoint' => '/bind']), ['name' => 'sdb1', 'mountpoint' => '/data', 'type' => 'xfs', 'total-bytes' => 2000, 'used-bytes' => 1000], ...array_map(static fn (string $type): array => array_replace($root, ['type' => $type, 'name' => $type]), ['tmpfs', 'nfs4', 'iso9660', 'overlay'])]);
        self::assertSame(['state' => 'available', 'used_bytes' => 1250.0, 'total_bytes' => 3000.0, 'filesystems' => 2, 'partial' => false], $report);
        self::assertStringNotContainsString('/private', json_encode($report));
    }

    public function testWindowsVolumesAndAnEmptyFilesystemAreMeasured(): void
    {
        $report = VmFilesystemUsage::fromQga([
            ['name' => 'volume-c', 'mountpoint' => 'C:\\', 'type' => 'NTFS', 'total-bytes' => 1000, 'used-bytes' => 200],
            ['name' => 'volume-d', 'mountpoint' => 'D:\\', 'type' => 'ReFS', 'total-bytes' => 3000, 'used-bytes' => 0],
        ]);
        self::assertSame(200.0, $report['used_bytes']);
        self::assertSame(4000.0, $report['total_bytes']);
        self::assertSame(2, $report['filesystems']);
        self::assertFalse($report['partial']);
    }

    public function testPartialAndInvalidDataNeverClaimCompleteUsage(): void
    {
        $root = ['name' => 'sda1', 'mountpoint' => '/', 'type' => 'ext4', 'total-bytes' => 1000, 'used-bytes' => 0];
        foreach ([-1, 1001, INF, '500', 1.5, null] as $bad) {
            $report = VmFilesystemUsage::fromQga([$root, array_replace($root, ['name' => 'bad', 'used-bytes' => $bad])]);
            self::assertTrue($report['partial']);
            self::assertSame(0.0, $report['used_bytes']);
            self::assertSame(1000.0, $report['total_bytes']);
        }
        foreach ([null, ['result' => []], [], array_fill(0, 129, $root), [$root, array_replace($root, ['used-bytes' => 1])]] as $bad) {
            self::assertSame(VmFilesystemUsage::unavailable(), VmFilesystemUsage::fromQga($bad));
        }
        $shared = ['name' => 'pool/data', 'mountpoint' => '/data', 'type' => 'zfs', 'disk' => [['dev' => 'shared']]];
        self::assertTrue(VmFilesystemUsage::fromQga([$root, $shared])['partial']);
    }

    public function testHeartbeatCannotForwardExtraFieldsOrInvalidAggregates(): void
    {
        $valid = ['state' => 'available', 'used_bytes' => 0, 'total_bytes' => 1000, 'filesystems' => 1, 'partial' => false];
        self::assertSame(0.0, VmFilesystemUsage::sanitize($valid + ['host_secret' => 'private'])['used_bytes']);
        self::assertArrayNotHasKey('host_secret', VmFilesystemUsage::sanitize($valid + ['host_secret' => 'private']));
        foreach ([['used_bytes' => 1001], ['used_bytes' => -1], ['total_bytes' => 0], ['filesystems' => 129], ['partial' => 'false']] as $invalid) {
            self::assertSame(VmFilesystemUsage::unavailable(), VmFilesystemUsage::sanitize(array_replace($valid, $invalid)));
        }
    }
}
