<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Application\EncryptionService;
use App\Module\Core\Domain\Entity\AppSetting;
use App\Repository\AppSettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class AppSettingsValueNormalizationTest extends TestCase
{
    public function testUnsupportedStoredValuesFallBackAndSlotBoundsRemainConsistent(): void
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findBy')->willReturn([
            new AppSetting(AppSettingsService::KEY_SITE_TITLE, new \stdClass()),
            new AppSetting(AppSettingsService::KEY_GAMESERVER_MIN_SLOTS, 12),
            new AppSetting(AppSettingsService::KEY_GAMESERVER_MAX_SLOTS, 8),
            new AppSetting(AppSettingsService::KEY_GAMESERVER_DEFAULT_SLOTS, 100),
        ]);
        $service = new AppSettingsService($this->settingsRepository($repository), $this->createStub(EntityManagerInterface::class), new EncryptionService(null, null), new NullLogger());

        self::assertSame($service->getSiteTitle(), $service->getSettings()[AppSettingsService::KEY_SITE_TITLE]);
        self::assertNotSame('', $service->getSiteTitle());
        self::assertSame(8, $service->getGameserverMinSlots());
        self::assertSame(8, $service->getGameserverMaxSlots());
        self::assertSame(8, $service->getGameserverDefaultSlots());
    }

    public function testStructuredMonitoringSettingsArePreserved(): void
    {
        $value = ['enabled' => true, 'interval' => 60, 'targets' => ['internal', 'external']];
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findBy')->willReturn([new AppSetting(AppSettingsService::KEY_INFRA_MONITORING, $value)]);
        $service = new AppSettingsService($this->settingsRepository($repository), $this->createStub(EntityManagerInterface::class), new EncryptionService(null, null), new NullLogger());

        self::assertSame($value, $service->getSettings()[AppSettingsService::KEY_INFRA_MONITORING]);
    }
    private function settingsRepository(EntityRepository $inner): AppSettingRepository
    {
        $repository = new AppSettingRepository($this->createStub(ManagerRegistry::class));
        (new \ReflectionProperty(ServiceEntityRepository::class, 'repository'))->setValue($repository, $inner);

        return $repository;
    }
}
