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

final class CmsSettingsProviderTest extends TestCase
{
    public function testThemeResolverUsesOnlyCmsSiteSettingWhenPresent(): void
    {
        [$provider] = $this->createProviderWithStorage($storage);
        $resolver = new ThemeResolver($provider);

        $site = new Site('Demo', 'demo.local');
        $provider->save($site, 'theme2', [], CmsSettingsProvider::DEFAULT_MODULE_TOGGLES);

        self::assertSame('minimal', $resolver->resolveThemeKey($site));
    }

    public function testThemeResolverIgnoresLegacySiteTemplateKey(): void
    {
        [$provider] = $this->createProviderWithStorage($storage);
        $resolver = new ThemeResolver($provider);

        $site = new Site('Demo', 'demo.local');
        $site->setCmsTemplateKey('esports');

        self::assertSame('minimal', $resolver->resolveThemeKey($site));
    }

    public function testModuleTogglesAreSavedAndLoaded(): void
    {
        [$provider] = $this->createProviderWithStorage($storage);

        $site = new Site('Demo', 'demo.local');
        $provider->save($site, null, [
            'logo_path' => '/logo.svg',
            'socials' => ['discord' => 'https://discord.gg/example'],
        ], [
            'blog' => true,
            'events' => true,
            'team' => false,
            'forum' => false,
            'media' => true,
        ]);

        $loadedToggles = $provider->getModuleToggles($site);
        self::assertSame(
            array_keys(CmsSettingsProvider::DEFAULT_MODULE_TOGGLES),
            array_keys($loadedToggles),
            'All CMS modules must be represented in site settings toggles.',
        );
        self::assertTrue($loadedToggles['blog']);
        self::assertTrue($loadedToggles['events']);
        self::assertFalse($loadedToggles['team']);

        $branding = $provider->getBranding($site);
        self::assertSame('/logo.svg', $branding['logo_path']);
        self::assertSame('https://discord.gg/example', $branding['socials']['discord']);
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
