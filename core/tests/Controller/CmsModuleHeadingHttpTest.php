<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Cms\Application\CmsTranslations;
use App\Module\Core\Domain\Entity\CmsPage;
use App\Module\Core\Domain\Entity\CmsSiteSettings;
use App\Module\Core\Domain\Entity\ModuleSetting;
use App\Module\Core\Domain\Entity\Site;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CmsModuleHeadingHttpTest extends AbstractWebTestCase
{
    /** @var array<string,?bool> */
    private array $previous = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSite();
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        foreach (['cms', 'shop', 'billing'] as $key) {
            $setting = $em->getRepository(ModuleSetting::class)->findOneBy(['moduleKey' => $key]);
            $this->previous[$key] = $setting?->isEnabled();
            if (null === $setting) {
                $setting = new ModuleSetting($key, '1', true);
                $em->persist($setting);
            }
            $setting->setEnabled(true);
        }
        $site = $em->getRepository(Site::class)->findOneBy(['host' => 'localhost']);
        $em->persist(new CmsPage($site, 'Über uns', 'ueber-uns', true));
        $em->flush();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (null !== self::$kernel) {
            $em = self::getContainer()->get(EntityManagerInterface::class);
            foreach ($this->previous as $key => $enabled) {
                $setting = $em->getRepository(ModuleSetting::class)->findOneBy(['moduleKey' => $key]);
                if (null !== $setting) {
                    null === $enabled ? $em->remove($setting) : $setting->setEnabled($enabled);
                }
            }
            $em->flush();
        }
        parent::tearDown();
    }

    public static function themesAndLanguages(): iterable
    {
        foreach (['hosting', 'clan', 'private'] as $theme) {
            foreach (['de', 'en'] as $locale) {
                yield $theme.'-'.$locale => [$theme, $locale];
            }
        }
    }

    #[DataProvider('themesAndLanguages')]
    public function testModuleHeadingsMatchHomepageAndAboutUs(string $theme, string $locale): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $site = $em->getRepository(Site::class)->findOneBy(['host' => 'localhost']);
        $em->getRepository(CmsSiteSettings::class)->findOneBy(['site' => $site])->setActiveTheme($theme);
        $em->flush();
        $before = $em->getConnection()->fetchAllAssociative('SELECT * FROM cms_site_settings ORDER BY id');
        foreach (['/', '/ueber-uns', '/teams', '/events', '/blog', '/forum', '/media', '/servers', '/shop'] as $path) {
            $view = $client->request('GET', $path.'?lang='.$locale);
            self::assertResponseIsSuccessful($theme.' '.$locale.' '.$path);
            self::assertCount(1, $view->filterXPath('//main//*[@data-cms-page-heading]'));
            self::assertCount(1, $view->filterXPath('//*[@data-cms-page-heading]/*[contains(@class,"cms-page-heading__inner")]'));
            self::assertCount(1, $view->filterXPath('//main//h1'));
            self::assertSame($theme, $view->filterXPath('//body')->attr('data-cms-theme'));
            self::assertDoesNotMatchRegularExpression('/\b(?:media_home|team_intro|events_intro|blog_intro|forum_intro|media_intro|shop_title|shop_intro|shop_eyebrow)\b/', $view->filterXPath('//main')->text());
            if ('/shop' === $path) {
                self::assertSame('Shop', $view->filterXPath('//h1')->text());
            }
        }
        self::assertSame($before, self::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchAllAssociative('SELECT * FROM cms_site_settings ORDER BY id'));
    }

    public function testAnOlderCmsCatalogueCannotExposeMessageKeysInThePage(): void
    {
        $client = self::createClient();
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $site = $em->getRepository(Site::class)->findOneBy(['host' => 'localhost']);
        $em->getRepository(CmsSiteSettings::class)->findOneBy(['site' => $site])->setActiveTheme('hosting');
        $em->flush();
        $stale = new class () implements TranslatorInterface {
            /** @var list<string> */
            public array $keys = [];
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                $this->keys[] = $id;
                return $id;
            }
            public function getLocale(): string
            {
                return 'de';
            }
        };
        $container->set(CmsTranslations::class, new CmsTranslations($stale, $container->get(RequestStack::class), dirname(__DIR__, 2).'/translations'));
        $view = $client->request('GET', '/teams?lang=de');
        self::assertResponseIsSuccessful();
        self::assertContains('team_intro', $stale->keys);
        self::assertStringContainsString('Die Menschen und Teams', $view->filterXPath('//*[@data-cms-page-heading]')->text());
        self::assertStringNotContainsString('team_intro', $view->filterXPath('//main')->text());
    }
}
