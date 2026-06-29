<?php

declare(strict_types=1);

namespace App\Tests\Module\Musicbot\Application\Backup;

use App\Module\Core\Domain\Enum\UserType;
use App\Module\Musicbot\Application\Backup\MusicbotBackupDataProvider;
use App\Module\Musicbot\Application\Backup\MusicbotBackupManifest;
use App\Module\Musicbot\Application\Backup\MusicbotBackupOptions;
use App\Module\Musicbot\Application\Backup\MusicbotBackupService;
use App\Module\Musicbot\Application\Backup\MusicbotBackupType;
use App\Module\Musicbot\Application\MusicbotSecretConfigServiceInterface;
use App\Module\Musicbot\Domain\Entity\MusicbotConnection;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Enum\MusicbotPlatform;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class MusicbotBackupServiceTest extends TestCase
{
    private MusicbotBackupService $service;
    private MockObject&MusicbotSecretConfigServiceInterface $secretService;
    private MockObject&MusicbotBackupDataProvider $dataProvider;

    protected function setUp(): void
    {
        $this->secretService = $this->createMock(MusicbotSecretConfigServiceInterface::class);
        $this->dataProvider = $this->createMock(MusicbotBackupDataProvider::class);
        $this->rebuildService();
    }

    private function rebuildService(): void
    {
        $this->service = new MusicbotBackupService(
            secretConfigService: $this->secretService,
            dataProvider: $this->dataProvider,
            appVersion: '1.0.0-test',
        );
    }

    private function configureEmptyDataProvider(): void
    {
        $this->dataProvider->method('getConnections')->willReturn([]);
        $this->dataProvider->method('getPlaylists')->willReturn([]);
        $this->dataProvider->method('getRadioStations')->willReturn([]);
        $this->dataProvider->method('getAutoDjSettings')->willReturn(null);
        $this->dataProvider->method('getPlugins')->willReturn([]);
    }

    public function testCustomerBackupMasksSecrets(): void
    {
        $instance = $this->buildMockInstance();
        $options = MusicbotBackupOptions::fromArray(['type' => 'customer'], false);

        $connection = $this->createMock(MusicbotConnection::class);
        $connection->method('getPlatform')->willReturn(MusicbotPlatform::Teamspeak);
        $connection->method('isEnabled')->willReturn(true);
        $connection->method('getConnectionConfig')->willReturn(['host' => 'ts.example.com']);
        $connection->method('getSecretConfig')->willReturn(['server_password' => 'supersecret']);

        $this->secretService->method('mask')->willReturn(['server_password' => '********']);

        $dp = $this->buildDataProviderWithConnections([$connection]);
        $service = $this->buildServiceWith($dp);

        $manifest = $service->createBackup($instance, $options);

        self::assertSame('customer', $manifest->backupType);
        self::assertSame('1', $manifest->schemaVersion);

        $connections = $manifest->data['connections'] ?? [];
        self::assertCount(1, $connections);
        self::assertSame('********', $connections[0]['secret_config']['server_password'] ?? null);
    }

    public function testAdminBackupCanIncludeFullSecrets(): void
    {
        $instance = $this->buildMockInstance();
        $options = MusicbotBackupOptions::fromArray(['type' => 'admin', 'mask_secrets' => false], true);

        $connection = $this->createMock(MusicbotConnection::class);
        $connection->method('getPlatform')->willReturn(MusicbotPlatform::Teamspeak);
        $connection->method('isEnabled')->willReturn(true);
        $connection->method('getConnectionConfig')->willReturn(['host' => 'ts.example.com']);
        $connection->method('getSecretConfig')->willReturn(['server_password' => 'v1:real-encrypted']);

        $dp = $this->buildDataProviderWithConnections([$connection]);
        $service = $this->buildServiceWith($dp);

        $manifest = $service->createBackup($instance, $options);

        self::assertSame('admin', $manifest->backupType);

        $connections = $manifest->data['connections'] ?? [];
        self::assertCount(1, $connections);
        self::assertSame('v1:real-encrypted', $connections[0]['secret_config']['server_password'] ?? null);
    }

    public function testMinimalBackupTypeIsPreserved(): void
    {
        $instance = $this->buildMockInstance();
        $options = MusicbotBackupOptions::fromArray(['type' => 'minimal'], false);
        $this->configureEmptyDataProvider();

        $manifest = $this->service->createBackup($instance, $options);

        self::assertSame('minimal', $manifest->backupType);
    }

    public function testNonAdminCannotElevateToAdminBackupType(): void
    {
        $options = MusicbotBackupOptions::fromArray(['type' => 'admin'], false);

        self::assertSame(MusicbotBackupType::Customer, $options->type);
    }

    public function testChecksumIsVerified(): void
    {
        $instance = $this->buildMockInstance();
        $options = MusicbotBackupOptions::fromArray([], false);
        $this->configureEmptyDataProvider();

        $manifest = $this->service->createBackup($instance, $options);

        self::assertTrue($this->service->verifyChecksum($manifest));
    }

    public function testChecksumFailsOnTamperedManifest(): void
    {
        $instance = $this->buildMockInstance();
        $options = MusicbotBackupOptions::fromArray([], false);
        $this->configureEmptyDataProvider();

        $manifest = $this->service->createBackup($instance, $options);

        $tampered = new MusicbotBackupManifest(
            schemaVersion: $manifest->schemaVersion,
            backupType: $manifest->backupType,
            instanceId: $manifest->instanceId,
            customerId: $manifest->customerId,
            serviceName: $manifest->serviceName,
            appVersion: $manifest->appVersion,
            createdAt: $manifest->createdAt,
            data: array_merge($manifest->data, ['injected' => 'malicious']),
            checksum: $manifest->checksum,
        );

        self::assertFalse($this->service->verifyChecksum($tampered));
    }

    public function testSerializeDeserializeRoundtrip(): void
    {
        $instance = $this->buildMockInstance();
        $options = MusicbotBackupOptions::fromArray([], false);
        $this->configureEmptyDataProvider();

        $manifest = $this->service->createBackup($instance, $options);
        $json = $this->service->serializeToJson($manifest);
        $restored = $this->service->deserializeFromJson($json);

        self::assertSame($manifest->instanceId, $restored->instanceId);
        self::assertSame($manifest->checksum, $restored->checksum);
    }

    public function testValidateManifestRejectsUnknownSchemaVersion(): void
    {
        $manifest = new MusicbotBackupManifest(
            schemaVersion: '99',
            backupType: 'customer',
            instanceId: '1',
            customerId: '1',
            serviceName: 'bot-1',
            appVersion: '1.0',
            createdAt: new \DateTimeImmutable(),
            data: [],
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->service->validateManifest($manifest);
    }

    public function testValidateManifestRejectsMissingInstanceId(): void
    {
        $manifest = new MusicbotBackupManifest(
            schemaVersion: MusicbotBackupManifest::SCHEMA_VERSION,
            backupType: 'customer',
            instanceId: '',
            customerId: '1',
            serviceName: 'bot-1',
            appVersion: '1.0',
            createdAt: new \DateTimeImmutable(),
            data: [],
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->service->validateManifest($manifest);
    }

    public function testInstanceConfigStripsRuntimeSecrets(): void
    {
        $instance = $this->buildMockInstance([
            'runtime_control_token' => 'secret-token',
            'volume' => 80,
        ]);
        $options = MusicbotBackupOptions::fromArray(['type' => 'customer'], false);

        $manifest = $this->service->createBackup($instance, $options);

        $instanceData = $manifest->data['instance'] ?? [];
        $config = $instanceData['instance_config'] ?? [];
        self::assertArrayNotHasKey('runtime_control_token', $config);
        self::assertArrayHasKey('volume', $config);
    }

    private function buildDataProviderWithConnections(array $connections): MockObject&MusicbotBackupDataProvider
    {
        $dp = $this->createMock(MusicbotBackupDataProvider::class);
        $dp->method('getConnections')->willReturn($connections);
        $dp->method('getPlaylists')->willReturn([]);
        $dp->method('getRadioStations')->willReturn([]);
        $dp->method('getAutoDjSettings')->willReturn(null);
        $dp->method('getPlugins')->willReturn([]);

        return $dp;
    }

    private function buildServiceWith(MusicbotBackupDataProvider $dp): MusicbotBackupService
    {
        return new MusicbotBackupService(
            secretConfigService: $this->secretService,
            dataProvider: $dp,
            appVersion: '1.0.0-test',
        );
    }

    private function buildMockInstance(array $instanceConfig = ['volume' => 80]): MockObject&MusicbotInstance
    {
        $customer = new \App\Module\Core\Domain\Entity\User('customer@example.com', UserType::Customer);
        $node = $this->createMock(\App\Module\Core\Domain\Entity\Agent::class);
        $node->method('getId')->willReturn('node-1');

        $instance = $this->createMock(MusicbotInstance::class);
        $instance->method('getId')->willReturn(42);
        $instance->method('getName')->willReturn('Test Bot');
        $instance->method('getStatus')->willReturn(\App\Module\Musicbot\Domain\Enum\MusicbotInstanceStatus::Running);
        $instance->method('getServiceName')->willReturn('musicbot-42');
        $instance->method('getInstallPath')->willReturn('/opt/easywi/musicbot/musicbot-42');
        $instance->method('getCpuLimit')->willReturn(100);
        $instance->method('getRamLimit')->willReturn(512);
        $instance->method('getDiskLimit')->willReturn(2048);
        $instance->method('getInstanceConfig')->willReturn($instanceConfig);
        $instance->method('getRuntimePayload')->willReturn(null);
        $instance->method('getCustomer')->willReturn($customer);
        $instance->method('getNode')->willReturn($node);

        return $instance;
    }
}
