<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Module\Cms\Application\CmsMaintenanceService;
use App\Module\Cms\Application\CmsRenderingFlowResolver;
use App\Module\Cms\Application\ThemeResolver;
use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Domain\Entity\MaintenanceWindow;
use App\Module\Core\Domain\Entity\Site;
use App\Module\Cms\Application\CmsMaintenanceWindowProviderInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class CmsRenderingFlowResolverTest extends TestCase
{
    public function testWindowMaintenanceOverridesNormalRenderingBySchedule(): void
    {
        $settingsRepository = $this->createMock(\App\Repository\CmsSiteSettingsRepository::class);
        $settingsRepository->method('findOneBySite')->willReturn(null);
        $entityManager = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $themeResolver = new ThemeResolver(new \App\Module\Cms\Application\CmsSettingsProvider($settingsRepository, $entityManager));

        $appSettings = $this->createMock(AppSettingsService::class);
        $appSettings->method('getSettings')->willReturn([]);
        $maintenanceService = new CmsMaintenanceService($appSettings);

        $repo = $this->createMock(CmsMaintenanceWindowProviderInterface::class);
        $repo->method('findCurrentPublicBySite')->willReturn([
            new MaintenanceWindow(5, 'Deployment', new \DateTimeImmutable('-2 minutes'), new \DateTimeImmutable('+10 minutes'), 'Planned downtime', true),
        ]);

        $resolver = new CmsRenderingFlowResolver($themeResolver, $maintenanceService, $repo);

        $site = new Site('Demo', 'demo.local');
        $ref = new \ReflectionProperty($site, 'id');
        $ref->setAccessible(true);
        $ref->setValue($site, 5);

        $resolved = $resolver->resolve(new Request(), $site);

        self::assertTrue($resolved['maintenance']['active']);
        self::assertSame('window', $resolved['maintenance']['scope']);
    }
}
