<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\InstanceSftpCredential;
use PHPUnit\Framework\TestCase;

final class InstanceSftpCredentialEntityTest extends TestCase
{
    public function testSetUsernameUpdatesToAgentProvidedValue(): void
    {
        $instance = $this->createMock(Instance::class);
        $credential = new InstanceSftpCredential($instance, 'sftp1', [
            'key_id' => 'k',
            'nonce' => 'n',
            'ciphertext' => 'c',
        ]);

        $credential->setUsername('gs_1_2');

        self::assertSame('gs_1_2', $credential->getUsername());
    }

    public function testProvisioningStateTransitionsTrackSuccessAndFailure(): void
    {
        $instance = $this->createMock(Instance::class);
        $credential = new InstanceSftpCredential($instance, 'sftp1', [
            'key_id' => 'k',
            'nonce' => 'n',
            'ciphertext' => 'c',
        ]);

        $credential->markProvisioningPending();
        self::assertFalse($credential->isProvisioned());
        self::assertSame('pending', $credential->getStatus());

        $rotatedAt = new \DateTimeImmutable('2026-05-06T12:00:00+00:00');
        $credential->markProvisioned($rotatedAt);
        self::assertTrue($credential->isProvisioned());
        self::assertSame('provisioned', $credential->getStatus());
        self::assertSame($rotatedAt, $credential->getRotatedAt());

        $credential->markProvisioningFailed('WINDOWS_SFTP_UNSUPPORTED', 'Windows SFTP is blocked.');
        self::assertFalse($credential->isProvisioned());
        self::assertSame('failed', $credential->getStatus());
        self::assertSame('WINDOWS_SFTP_UNSUPPORTED', $credential->getLastErrorCode());
    }

    public function testSetUsernameIgnoresEmptyInput(): void
    {
        $instance = $this->createMock(Instance::class);
        $credential = new InstanceSftpCredential($instance, 'sftp1', [
            'key_id' => 'k',
            'nonce' => 'n',
            'ciphertext' => 'c',
        ]);

        $credential->setUsername('   ');

        self::assertSame('sftp1', $credential->getUsername());
    }
}
