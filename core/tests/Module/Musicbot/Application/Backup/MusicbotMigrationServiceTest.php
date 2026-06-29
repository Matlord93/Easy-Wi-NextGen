<?php

declare(strict_types=1);

namespace App\Tests\Module\Musicbot\Application\Backup;

use App\Module\Musicbot\Application\Backup\MusicbotBackupManifest;
use App\Module\Musicbot\Application\Backup\MusicbotBackupType;
use App\Module\Musicbot\Application\Backup\MusicbotMigrationService;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class MusicbotMigrationServiceTest extends TestCase
{
    private MusicbotMigrationService $service;

    protected function setUp(): void
    {
        $this->service = new MusicbotMigrationService();
    }

    public function testMigrationRemovesRuntimeFields(): void
    {
        $manifest = $this->buildManifest([
            'instance' => [
                'name' => 'Bot',
                'service_name' => 'musicbot-1',
                'install_path' => '/opt/old/musicbot-1',
                'node_id' => 5,
                'runtime_payload' => ['state' => 'running'],
                'instance_config' => [
                    'control_sock' => '/var/run/musicbot.sock',
                    'volume' => 80,
                    'pulseaudio_sink' => 'sink-1',
                ],
            ],
            'runtime' => ['pid' => 1234],
            'tmp' => ['cache' => 'data'],
        ]);

        $migrated = $this->service->prepareForMigration($manifest, 'node-2');

        $instance = $migrated->data['instance'] ?? [];
        $config = $instance['instance_config'] ?? [];

        self::assertArrayNotHasKey('install_path', $instance);
        self::assertArrayNotHasKey('node_id', $instance);
        self::assertArrayNotHasKey('runtime_payload', $instance);
        self::assertArrayNotHasKey('control_sock', $config);
        self::assertArrayNotHasKey('pulseaudio_sink', $config);
        self::assertArrayHasKey('volume', $config);
        self::assertArrayNotHasKey('runtime', $migrated->data);
        self::assertArrayNotHasKey('tmp', $migrated->data);
    }

    public function testMigrationAddsTargetNodeMetadata(): void
    {
        $manifest = $this->buildManifest(['instance' => ['instance_config' => []]]);
        $migrated = $this->service->prepareForMigration($manifest, 'node-99');

        self::assertSame('node-99', $migrated->data['migration']['target_node_id'] ?? null);
        self::assertSame('musicbot-42', $migrated->data['migration']['original_service_name'] ?? null);
        self::assertSame(MusicbotBackupType::Admin->value, $migrated->backupType);
    }

    public function testMigrationWithCustomServiceName(): void
    {
        $manifest = $this->buildManifest(['instance' => ['instance_config' => []]]);
        $migrated = $this->service->prepareForMigration($manifest, 'node-3', 'musicbot-new-xyz');

        self::assertSame('musicbot-new-xyz', $migrated->serviceName);
    }

    public function testComputeNewPathsBuildsCorrectStructure(): void
    {
        $instance = $this->createMock(MusicbotInstance::class);
        $instance->method('getServiceName')->willReturn('musicbot-42');

        $paths = $this->service->computeNewPaths($instance, '/opt/easywi/musicbot');

        self::assertSame('/opt/easywi/musicbot/musicbot-42', $paths['install_path']);
        self::assertSame('/opt/easywi/musicbot/musicbot-42/uploads', $paths['uploads_path']);
        self::assertSame('/opt/easywi/musicbot/musicbot-42/playlists', $paths['playlists_path']);
        self::assertSame('/opt/easywi/musicbot/musicbot-42/settings', $paths['settings_path']);
    }

    public function testMigrationGeneratesNewChecksumForPreparedManifest(): void
    {
        $manifest = $this->buildManifest(['instance' => ['instance_config' => []]]);
        $migrated = $this->service->prepareForMigration($manifest, 'node-5');

        self::assertNotEmpty($migrated->checksum);
        self::assertNotSame($manifest->checksum, $migrated->checksum);
    }

    private function buildManifest(array $data = []): MusicbotBackupManifest
    {
        return new MusicbotBackupManifest(
            schemaVersion: MusicbotBackupManifest::SCHEMA_VERSION,
            backupType: MusicbotBackupType::Admin->value,
            instanceId: '42',
            customerId: '7',
            serviceName: 'musicbot-42',
            appVersion: '1.0.0',
            createdAt: new \DateTimeImmutable(),
            data: $data,
            checksum: 'original-checksum',
        );
    }
}
