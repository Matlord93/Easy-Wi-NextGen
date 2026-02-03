<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\PanelCustomer\Application\SftpFilesystemService;
use App\Repository\WebspaceRepository;
use App\Repository\WebspaceSftpCredentialRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class FilesHealthTest extends WebTestCase
{
    public function testHealthReportsMissingEnv(): void
    {
        self::ensureKernelShutdown();
        $this->unsetEnv();

        $client = static::createClient();
        $client->request('GET', '/files/health');

        $this->assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);

        $this->assertIsArray($payload);
        $this->assertFalse($payload['ok']);
        $this->assertContains('EASYWI_SFTP_HOST', $payload['missing']);
    }

    public function testHealthReportsUnreachableSftp(): void
    {
        self::ensureKernelShutdown();
        $this->setEnv();

        $webspace = $this->createMock(\App\Module\Core\Domain\Entity\Webspace::class);
        $webspace->method('getId')->willReturn(42);
        $webspace->method('getPath')->willReturn('/srv/webspace');

        $repo = $this->createMock(WebspaceRepository::class);
        $repo->method('find')->with('42')->willReturn($webspace);

        $credentialRepo = $this->createMock(WebspaceSftpCredentialRepository::class);
        $credentialRepo->method('findOneByWebspace')->with($webspace)->willReturn(
            $this->createMock(\App\Module\Core\Domain\Entity\WebspaceSftpCredential::class),
        );

        $mock = $this->createMock(SftpFilesystemService::class);
        $mock->method('testConnection')->with($webspace)->willThrowException(new \RuntimeException('Connection failed'));

        $client = static::createClient();
        static::getContainer()->set(WebspaceRepository::class, $repo);
        static::getContainer()->set(WebspaceSftpCredentialRepository::class, $credentialRepo);
        static::getContainer()->set(SftpFilesystemService::class, $mock);

        $client->request('GET', '/files/health?webspace=42');

        $this->assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);

        $this->assertIsArray($payload);
        $this->assertFalse($payload['ok']);
        $this->assertStringContainsString('Connection failed', $payload['message']);
    }

    private function unsetEnv(): void
    {
        foreach (['EASYWI_SFTP_HOST', 'EASYWI_SFTP_PORT'] as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }

    private function setEnv(): void
    {
        putenv('EASYWI_SFTP_HOST=localhost');
        putenv('EASYWI_SFTP_PORT=22');

        $_ENV['EASYWI_SFTP_HOST'] = 'localhost';
        $_ENV['EASYWI_SFTP_PORT'] = '22';

        $_SERVER['EASYWI_SFTP_HOST'] = 'localhost';
        $_SERVER['EASYWI_SFTP_PORT'] = '22';
    }
}
