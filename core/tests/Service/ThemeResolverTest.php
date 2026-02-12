<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Module\Cms\Application\CmsSettingsProvider;
use App\Module\Cms\Application\ThemeResolver;
use App\Module\Core\Domain\Entity\CmsSiteSettings;
use App\Module\Core\Domain\Entity\Site;
use App\Repository\CmsSiteSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class ThemeResolverTest extends TestCase
{
    public function testActiveThemeEsportsResolvesToEsports(): void
    {
        [$provider] = $this->createProviderWithStorage($storage);
        $resolver = new ThemeResolver($provider);

        $site = new Site('Demo', 'demo.local');
        $provider->save($site, 'esports', [], CmsSettingsProvider::DEFAULT_MODULE_TOGGLES);

        self::assertSame('esports', $resolver->resolveThemeKey($site));
    }

    public function testFallbackReturnsMinimalWhenNoCmsSettingExists(): void
    {
        [$provider] = $this->createProviderWithStorage($storage);
        $resolver = new ThemeResolver($provider);

        $site = new Site('Demo', 'demo.local');
        $site->setCmsTemplateKey('unknown-theme');

        self::assertSame('minimal', $resolver->resolveThemeKey($site));
    }

    public function testLegacyTheme4AliasResolvesToXenal(): void
    {
        [$provider] = $this->createProviderWithStorage($storage);
        $resolver = new ThemeResolver($provider);

        $site = new Site('Demo', 'demo.local');
        $provider->save($site, 'theme4', [], CmsSettingsProvider::DEFAULT_MODULE_TOGGLES);

        self::assertSame('xenal', $resolver->resolveThemeKey($site));
    }

    /**
     * @param array<string, CmsSiteSettings>|null $storage
     * @return array{CmsSettingsProvider, EntityManagerInterface}
     */
    private function createProviderWithStorage(?array &$storage = null): array
    {
        $storage ??= [];

        $repository = $this->createMock(CmsSiteSettingsRepository::class);
        $repository->method('findOneBySite')->willReturnCallback(static function (Site $site) use (&$storage): ?CmsSiteSettings {
            return $storage[spl_object_hash($site)] ?? null;
        });

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$storage): void {
            if (!$entity instanceof CmsSiteSettings) {
                return;
            }

            $storage[spl_object_hash($entity->getSite())] = $entity;
        });

        return [new CmsSettingsProvider($repository, $entityManager), $entityManager];
    }
}
