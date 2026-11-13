<?php

declare(strict_types=1);

namespace App\Tests\Module\Nodes\Application;

use App\Module\Nodes\Application\VmProvisioningSettings;
use phpseclib3\Crypt\EC;
use PHPUnit\Framework\TestCase;

final class VmProvisioningSettingsTest extends TestCase
{
    public function testPublicKeysAreParsedAndDeduplicated(): void
    {
        $key = EC::createKey('Ed25519')->getPublicKey()->toString('OpenSSH');
        self::assertSame([$key], VmProvisioningSettings::publicKeys($key."\n".$key));
    }

    public function testInvalidKeysAndOptionsAreRejected(): void
    {
        $key = EC::createKey('Ed25519')->getPublicKey()->toString('OpenSSH');
        foreach (['', 'ssh-ed25519 AAAA', 'command="exec" '.$key, str_repeat('x', 32769), implode("\n", array_fill(0, 9, $key))] as $input) {
            try {
                VmProvisioningSettings::publicKeys($input);
                self::fail('Invalid key accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertSame('vm_config.invalid_keys', $error->getMessage());
            }
        }
    }

    public function testOnlyCloudInitVolumesAndExistingBootDevicesAreSelected(): void
    {
        $config = ['ide2' => 'local-lvm:vm-101-cloudinit,media=cdrom', 'scsi0' => 'local-lvm:vm-101-disk-0', 'net0' => 'virtio,bridge=vmbr0', 'scsi31' => 'outside', 'args' => '-danger'];
        self::assertTrue(VmProvisioningSettings::hasCloudInit($config));
        self::assertSame(['net0', 'scsi0'], VmProvisioningSettings::bootDevices($config));
        self::assertFalse(VmProvisioningSettings::hasCloudInit(['ide2' => 'local:iso/cloudinit.iso,media=cdrom']));
        self::assertTrue(VmProvisioningSettings::hasCloudInit(['ide2' => 'local:101/vm-101-cloudinit.qcow2,media=cdrom']));
        self::assertFalse(VmProvisioningSettings::proxmoxView($config + ['cicustom' => 'user=local:snippets/custom.yaml'], 'web')['supported']);
    }
}
