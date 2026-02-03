<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\PanelCustomer\Application\SftpFilesystemService;
use App\Module\Core\Application\AppSettingsService;
use App\Repository\WebspaceRepository;
use App\Repository\WebspaceSftpCredentialRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class FilesHealthTest extends WebTestCase
{
    public function testHealthReportsMissingEnv(): void
    {
        self::ensureKernelShutdown();
        $settingsService = $this->createMock(AppSettingsService::class);
        $settingsService->method('getSftpHost')->willReturn(null);
        $settingsService->method('getSftpPort')->willReturn(22);
        $settingsService->method('getSettings')->willReturn([]);

        $client = static::createClient();
        static::getContainer()->set(AppSettingsService::class, $settingsService);
        $client->request('GET', '/files/health');

        $this->assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);

        $this->assertIsArray($payload);
        $this->assertFalse($payload['ok']);
        $this->assertContains('sftp_host', $payload['missing']);
    }

    public function testHealthReportsUnreachableSftp(): void
    {
        self::ensureKernelShutdown();
        $settingsService = $this->createMock(AppSettingsService::class);
        $settingsService->method('getSftpHost')->willReturn('localhost');
        $settingsService->method('getSftpPort')->willReturn(22);
        $settingsService->method('getSettings')->willReturn([
            AppSettingsService::KEY_SFTP_HOST => 'localhost',
            AppSettingsService::KEY_SFTP_PORT => 22,
        ]);

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
        static::getContainer()->set(AppSettingsService::class, $settingsService);

        $client->request('GET', '/files/health?webspace=42');

        $this->assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);

        $this->assertIsArray($payload);
        $this->assertFalse($payload['ok']);
        $this->assertStringContainsString('Connection failed', $payload['message']);
    }

}
