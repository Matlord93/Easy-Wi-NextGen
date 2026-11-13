<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Module\Core\Application\Exception\SftpException;
use App\Module\Core\Application\SshHostKeyVerifier;
use App\Module\Core\Domain\Entity\Agent;
use phpseclib3\Net\SFTP;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SshHostKeyVerifierTest extends TestCase
{
    public static function invalidTrust(): array
    {
        return [[null], [''], ['MD5:aa:bb:cc'], ['SHA256:short']];
    }

    #[DataProvider('invalidTrust')]
    public function testMalformedTrustDoesNotContactTheServer(?string $fingerprint): void
    {
        $connection = $this->createMock(SFTP::class);
        $connection->expects(self::never())->method('getServerPublicHostKey');
        $connection->expects(self::never())->method('login');
        $this->expectException(SftpException::class);
        (new SshHostKeyVerifier())->verify($connection, $fingerprint);
    }

    public function testUnavailableServerKeyIsRejected(): void
    {
        $fingerprint = 'SHA256:'.rtrim(base64_encode(hash('sha256', 'trusted', true)), '=');
        $connection = $this->createMock(SFTP::class);
        $connection->expects(self::once())->method('getServerPublicHostKey')->willReturn(false);
        $connection->expects(self::never())->method('login');
        $this->expectException(SftpException::class);
        (new SshHostKeyVerifier())->verify($connection, $fingerprint);
    }

    public function testHeartbeatMetadataCannotReplaceAdminTrust(): void
    {
        $fingerprint = 'SHA256:'.rtrim(base64_encode(hash('sha256', 'trusted', true)), '=');
        $node = new Agent('synthetic', ['key_id' => 'v1', 'nonce' => 'n', 'ciphertext' => 'c']);
        $node->setSftpHostFingerprint($fingerprint);
        $node->setMetadata(['sftp_host_fingerprint' => 'attacker-value']);
        self::assertSame($fingerprint, $node->getSftpHostFingerprint());
    }
}
