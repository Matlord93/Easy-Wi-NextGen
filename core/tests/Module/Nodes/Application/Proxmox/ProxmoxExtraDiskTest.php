<?php

declare(strict_types=1);

namespace App\Tests\Module\Nodes\Application\Proxmox;

use App\Module\Nodes\Application\Proxmox\ProxmoxExtraDisk;
use PHPUnit\Framework\TestCase;

final class ProxmoxExtraDiskTest extends TestCase
{
    public function testOnlyFreeSupportedSlotsCanBeAllocated(): void
    {
        $slots = ProxmoxExtraDisk::slots(['scsi0' => 'existing', 'virtio0' => 'existing'], 'qemu');
        self::assertSame('scsi1', $slots[0]);
        self::assertNotContains('scsi0', $slots);
        self::assertNotContains('virtio0', $slots);
        self::assertContains('scsi30', $slots);
        self::assertContains('virtio15', $slots);
        self::assertNotContains('virtio16', $slots);
        self::assertContains('mp255', ProxmoxExtraDisk::slots([], 'lxc'));
        self::assertNotContains('rootfs', ProxmoxExtraDisk::slots([], 'lxc'));
    }

    public function testLxcUsesANewManagedMountInsteadOfAHostBindPath(): void
    {
        $spec = ProxmoxExtraDisk::specification(['digest' => 'v1'], 'lxc', ['confirm' => '1', 'expected_digest' => 'v1', 'device' => 'mp0', 'storage' => 'local-lvm', 'size_gib' => '8', 'mountpoint' => '/mnt/data']);
        self::assertSame('/mnt/data', $spec['mountpoint']);
        self::assertSame(8, $spec['size_gib']);
    }

    public function testUnconfirmedStaleOccupiedAndInjectedParametersAreRejected(): void
    {
        $valid = ['confirm' => '1', 'expected_digest' => 'v1', 'device' => 'scsi1', 'storage' => 'local-lvm', 'size_gib' => '8'];
        foreach ([['confirm' => ''], ['expected_digest' => 'stale'], ['device' => 'scsi0'], ['device' => 'scsi31'], ['storage' => '/dev/sda'], ['storage' => 'local,shared=1'], ['size_gib' => '0'], ['size_gib' => '65537'], ['size_gib' => '1.5']] as $change) {
            try {
                ProxmoxExtraDisk::specification(['digest' => 'v1', 'scsi0' => 'existing'], 'qemu', array_replace($valid, $change));
                self::fail('Invalid extra disk was accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        foreach (['/', '/etc', '/mnt/data/../escape', '/mnt/data,backup=0', '/srv/data/subdirectory'] as $path) {
            try {
                ProxmoxExtraDisk::specification(['digest' => 'v1'], 'lxc', array_replace($valid, ['device' => 'mp0', 'mountpoint' => $path]));
                self::fail('Unsafe mountpoint was accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $this->expectExceptionMessage('pve.error.mountpoint');
        ProxmoxExtraDisk::specification(['digest' => 'v1', 'mp1' => 'local-lvm:existing,mp=/mnt/data'], 'lxc', array_replace($valid, ['device' => 'mp0', 'mountpoint' => '/mnt/data']));
    }
}
