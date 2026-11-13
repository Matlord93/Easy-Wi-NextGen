<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Application\EncryptionService;
use App\Module\Core\Application\Exception\SftpException;
use App\Module\Core\Application\SftpConnectionFactory;
use App\Module\Core\Application\SftpFileService;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Gameserver\Application\GameServerPathResolver;
use App\Repository\InstanceSftpCredentialRepository;
use Doctrine\ORM\EntityManagerInterface;
use phpseclib3\Net\SFTP;

final class SftpHostTrustTest extends AbstractWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSite();
        self::bootKernel();
        self::getContainer()->get('cache.rate_limiter')->clear();
    }

    public function testUntrustedKeyDoesNotOpenAConnection(): void
    {
        $instance = $this->seedInstance();
        $connections = $this->createMock(SftpConnectionFactory::class);
        $connections->expects(self::never())->method('create');
        $this->expectException(SftpException::class);
        $this->service($connections)->readFile($instance, '', 'server.properties');
    }

    public function testMismatchedHostKeyNeverReceivesCredentials(): void
    {
        $instance = $this->seedInstance();
        $instance->getNode()->setSftpHostFingerprint('SHA256:'.rtrim(base64_encode(hash('sha256', 'trusted-key', true)), '='));
        $sftp = $this->createMock(SFTP::class);
        $sftp->expects(self::once())->method('getServerPublicHostKey')->willReturn('ssh-ed25519 '.base64_encode('attacker-key'));
        $sftp->expects(self::never())->method('login');
        $sftp->expects(self::once())->method('disconnect');
        $connections = $this->createStub(SftpConnectionFactory::class);
        $connections->method('create')->willReturn($sftp);
        try {
            $this->service($connections)->readFile($instance, '', 'server.properties');
            self::fail('Untrusted connection accepted.');
        } catch (SftpException $error) {
            self::assertSame('sftp_host_key_mismatch', $error->getErrorCode());
        }
    }

    public function testVerifiedHostKeyPrecedesPasswordLogin(): void
    {
        $instance = $this->seedInstance();
        $instance->setInstallPath('/srv/game/test');
        $instance->getNode()->setSftpHostFingerprint('SHA256:'.rtrim(base64_encode(hash('sha256', 'trusted-key', true)), '='));
        $verified = false;
        $sftp = $this->createMock(SFTP::class);
        $sftp->expects(self::once())->method('getServerPublicHostKey')->willReturnCallback(static function () use (&$verified): string {
            $verified = true;

            return 'ssh-ed25519 '.base64_encode('trusted-key');
        });
        $sftp->expects(self::once())->method('login')->with('test-user', 'test-password')->willReturnCallback(static function () use (&$verified): bool {
            self::assertTrue($verified);

            return true;
        });
        $sftp->method('is_dir')->willReturn(true);
        $sftp->expects(self::once())->method('get')->with('/srv/game/test/server.properties')->willReturn('motd=hello');
        $connections = $this->createStub(SftpConnectionFactory::class);
        $connections->method('create')->willReturn($sftp);
        self::assertSame('motd=hello', $this->service($connections)->readFile($instance, '', 'server.properties'));
    }

    public function testOnlyAdminWithCsrfCanPinHostKey(): void
    {
        $instance = $this->seedInstance();
        $id = $instance->getNode()->getId();
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->loginAsRole($client, 'admin');
        $fingerprint = 'SHA256:'.rtrim(base64_encode(hash('sha256', 'trusted-key', true)), '=');
        $url = '/admin/nodes/'.$id.'/sftp-host-key';
        $client->request('POST', $url, ['fingerprint' => $fingerprint, '_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        $token = self::getContainer()->get(\Symfony\Component\Security\Csrf\CsrfTokenManagerInterface::class)->getToken('node-sftp-host-key-'.$id)->getValue();
        $client->request('POST', $url, ['fingerprint' => 'not-a-fingerprint', '_token' => $token]);
        self::assertResponseStatusCodeSame(422);
        $client->request('POST', $url, ['fingerprint' => $fingerprint, '_token' => $token]);
        self::assertResponseStatusCodeSame(303);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertSame($fingerprint, $em->find(Agent::class, $id)->getSftpHostFingerprint());
        $client->request('GET', '/logout');
        $this->loginAsRole($client, 'customer');
        $client->request('POST', $url, ['fingerprint' => '', '_token' => $token]);
        self::assertResponseStatusCodeSame(401);
        self::assertSame($fingerprint, self::getContainer()->get(EntityManagerInterface::class)->find(Agent::class, $id)->getSftpHostFingerprint());
    }

    private function service(SftpConnectionFactory $connections): SftpFileService
    {
        $settings = $this->createStub(AppSettingsService::class);
        $settings->method('getSftpHost')->willReturn('sftp.example.test');
        $settings->method('getSftpPort')->willReturn(22);
        $settings->method('getSftpUsername')->willReturn('test-user');
        $settings->method('getSftpPassword')->willReturn('test-password');

        return new SftpFileService(self::getContainer()->get(GameServerPathResolver::class), $settings, self::getContainer()->get(InstanceSftpCredentialRepository::class), $this->createStub(EncryptionService::class), $connections);
    }
}
