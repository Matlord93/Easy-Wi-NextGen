<?php

declare(strict_types=1);

namespace App\Tests\Module\Musicbot\Application\Backup;

use App\Module\Core\Domain\Enum\UserType;
use App\Module\Musicbot\Application\Backup\MusicbotBackupDataProvider;
use App\Module\Musicbot\Application\Backup\MusicbotBackupManifest;
use App\Module\Musicbot\Application\Backup\MusicbotBackupType;
use App\Module\Musicbot\Application\Backup\MusicbotRestoreService;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class MusicbotRestoreServiceTest extends TestCase
{
    private MusicbotRestoreService $service;
    private MockObject&EntityManagerInterface $em;
    private MockObject&MusicbotBackupDataProvider $dataProvider;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->dataProvider = $this->createMock(MusicbotBackupDataProvider::class);
        $this->dataProvider->method('getConnections')->willReturn([]);
        $this->dataProvider->method('getPlaylists')->willReturn([]);
        $this->dataProvider->method('getRadioStations')->willReturn([]);
        $this->dataProvider->method('getAutoDjSettings')->willReturn(null);
        $this->dataProvider->method('getPlugins')->willReturn([]);

        $this->service = new MusicbotRestoreService(
            entityManager: $this->em,
            dataProvider: $this->dataProvider,
        );
    }

    public function testDryRunDoesNotPersist(): void
    {
        $this->em->expects(self::never())->method('persist');
        $this->em->expects(self::never())->method('flush');

        $manifest = $this->buildManifest(['instance' => ['instance_config' => ['volume' => 75]]]);
        $instance = $this->buildMockInstance();

        $report = $this->service->restore($instance, $manifest, true);

        self::assertTrue($report->success);
        self::assertTrue($report->dryRun);
    }

    public function testRestoreSkipsControlSockAndRuntime(): void
    {
        $manifest = $this->buildManifest([
            'control_sock' => '/run/musicbot.sock',
            'runtime' => ['pid' => 123],
            'tmp' => ['cache_path' => '/tmp/mb'],
            'logs' => ['path' => '/var/log/musicbot'],
        ]);

        $instance = $this->buildMockInstance();

        $report = $this->service->restore($instance, $manifest, true);

        self::assertTrue($report->success);
        $combined = implode(' ', $report->warnings);
        self::assertStringContainsString('control_sock', $combined);
        self::assertStringContainsString('runtime', $combined);
    }

    public function testRestoreReportHasRestoredSections(): void
    {
        $manifest = $this->buildManifest([
            'instance' => ['instance_config' => []],
            'radio_stations' => [],
        ]);

        $instance = $this->buildMockInstance();

        $report = $this->service->restore($instance, $manifest, true);

        self::assertTrue($report->success);
        self::assertContains('instance_config', $report->restored);
    }

    public function testRestoreSucceedsOnEmptyManifest(): void
    {
        $manifest = $this->buildManifest([]);
        $instance = $this->buildMockInstance();

        $report = $this->service->restore($instance, $manifest, true);

        self::assertTrue($report->success);
        self::assertNull($report->error);
    }

    private function buildManifest(array $data): MusicbotBackupManifest
    {
        return new MusicbotBackupManifest(
            schemaVersion: MusicbotBackupManifest::SCHEMA_VERSION,
            backupType: MusicbotBackupType::Customer->value,
            instanceId: '42',
            customerId: '7',
            serviceName: 'musicbot-42',
            appVersion: '1.0.0',
            createdAt: new \DateTimeImmutable(),
            data: $data,
            checksum: 'test-checksum',
        );
    }

    private function buildMockInstance(): MockObject&MusicbotInstance
    {
        $customer = new \App\Module\Core\Domain\Entity\User('c@x.com', UserType::Customer);
        $instance = $this->createMock(MusicbotInstance::class);
        $instance->method('getId')->willReturn(42);
        $instance->method('getCustomer')->willReturn($customer);
        $instance->method('getInstanceConfig')->willReturn([]);

        return $instance;
    }
}
