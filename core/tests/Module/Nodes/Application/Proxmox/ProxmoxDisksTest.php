<?php

declare(strict_types=1);

namespace App\Tests\Module\Nodes\Application\Proxmox;

use App\Module\Nodes\Application\Proxmox\ProxmoxDisks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProxmoxDisksTest extends TestCase
{
    public function testOnlyAttachedWritableStorageDisksAreOffered(): void
    {
        $config = ['scsi0' => 'local-lvm:vm-101-disk-0,size=32G', 'virtio1' => 'local:101/vm-101-disk-1.qcow2,size=1.5G', 'ide2' => 'local:iso/debian.iso,media=cdrom,size=1G', 'sata0' => '/dev/disk/by-id/private,size=32G', 'ide0' => 'local-lvm:vm-101-cloudinit,size=4M', 'scsi1' => 'local:disk,size=20G,ro=1', 'scsi2' => 'local:disk,size=20G,shared=1', 'unused0' => 'local:disk,size=20G', 'efidisk0' => 'local:efi,size=4M', 'tpmstate0' => 'local:tpm,size=4M', 'scsi3' => 'local:disk,size=20G,size=10G', 'scsi4' => 'local:disk', 'scsi5' => 'local:disk,size=1G,import-from=other:disk'];
        self::assertSame([
            ['device' => 'scsi0', 'size_bytes' => 32 * 1073741824, 'minimum_gib' => 33],
            ['device' => 'virtio1', 'size_bytes' => 1610612736, 'minimum_gib' => 2],
        ], ProxmoxDisks::attached($config, 'qemu'));
        self::assertSame([], ProxmoxDisks::attached($config, 'lxc'));
    }

    #[DataProvider('sizes')]
    public function testSizeParsingIsExactAndBounded(string $size, ?int $bytes): void
    {
        $result = ProxmoxDisks::attached(['rootfs' => 'local:vm-102-disk-0,size='.$size], 'lxc');
        self::assertSame($bytes, $result[0]['size_bytes'] ?? null);
    }

    public static function sizes(): iterable
    {
        yield ['32G', 34359738368];
        yield ['1536M', 1610612736];
        yield ['1024K', 1048576];
        yield ['1073741824', 1073741824];
        yield ['0.5T', 549755813888];
        yield ['64T', 70368744177664];
        yield ['64.000001T', null];
        yield ['0.000001G', null];
        yield ['0', null];
        yield ['-1G', null];
        yield ['+1G', null];
        yield ['1e3G', null];
        yield ['1G,ro=1', null];
        yield ['999999999999999T', null];
    }

    public function testLxcExcludesBindMountsAndOutOfRangeDevices(): void
    {
        $result = ProxmoxDisks::attached(['rootfs' => 'local:vm-102-disk-0,size=8G', 'mp0' => 'local:vm-102-disk-1,size=4G,mp=/data', 'mp1' => '/srv/private,mp=/private,size=10G', 'mp256' => 'local:disk,size=10G', 'mp01' => 'local:disk,size=10G'], 'lxc');
        self::assertSame(['mp0', 'rootfs'], array_column($result, 'device'));
    }
}
