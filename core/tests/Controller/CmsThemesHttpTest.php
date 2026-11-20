<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Cms\Application\CmsSettingsProvider;
use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Domain\Entity\CmsBlock;
use App\Module\Core\Domain\Entity\CmsPage;
use App\Module\Core\Domain\Entity\CmsSiteSettings;
use App\Module\Core\Domain\Entity\ModuleSetting;
use App\Module\Core\Domain\Entity\PublicServer;
use App\Module\Core\Domain\Entity\Site;
use App\Module\Core\Domain\Enum\UserType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;

final class CmsThemesHttpTest extends AbstractWebTestCase
{
    /** @var array<string, bool|null> */
    private array $previousModules = [];
    private bool $registrationEnabled = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSite();
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        foreach (['cms', 'shop', 'billing'] as $key) {
            $setting = $em->getRepository(ModuleSetting::class)->findOneBy(['moduleKey' => $key]);
            $this->previousModules[$key] = $setting?->isEnabled();
            if (null === $setting) {
                $setting = new ModuleSetting($key, '1', true);
                $em->persist($setting);
            }
            $setting->setEnabled(true);
        }
        $em->flush();
        $appSettings = self::getContainer()->get(AppSettingsService::class);
        $this->registrationEnabled = $appSettings->isRegistrationEnabled();
        $appSettings->updateSettings(['registration_enabled' => true]);
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (null !== self::$kernel) {
            self::getContainer()->get(AppSettingsService::class)->updateSettings(['registration_enabled' => $this->registrationEnabled]);
            $em = self::getContainer()->get(EntityManagerInterface::class);
            foreach ($this->previousModules as $key => $enabled) {
                $setting = $em->getRepository(ModuleSetting::class)->findOneBy(['moduleKey' => $key]);
                if (null !== $setting) {
                    null === $enabled ? $em->remove($setting) : $setting->setEnabled($enabled);
                }
            }
            $em->flush();
        }
        parent::tearDown();
    }

    public static function themes(): iterable
    {
        yield ['hosting'];
        yield ['clan'];
        yield ['private'];
    }

    #[DataProvider('themes')]
    public function testPublicModulesAndAuthenticationUseTheSelectedTheme(string $theme): void
    {
        $client = self::createClient();
        $this->configure($theme);
        foreach (['/', '/projekte', '/blog', '/events', '/teams', '/media', '/forum', '/shop', '/kontakt', '/servers', '/login', '/register'] as $url) {
            $view = $client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
            self::assertSame($theme, $view->filterXPath('//body')->attr('data-cms-theme'), $url);
            self::assertCount(1, $view->filterXPath('//*[@id="cms-main"]'), $url);
            self::assertCount(1, $view->filterXPath('//*[@id="cookie-banner"]'), $url);
        }
        $client->request('GET', '/cookies');
        self::assertResponseRedirects('/#cookie-policy');
        $client->request('POST', '/login?target=%2Fprojekte', ['email' => 'invalid-cms@example.test', 'password' => 'Wrong-password']);
        self::assertResponseStatusCodeSame(401);
        $view = $client->getCrawler();
        self::assertSame($theme, $view->filterXPath('//body')->attr('data-cms-theme'));
        self::assertSame('invalid-cms@example.test', $view->filterXPath('//form//input[@name="email"]')->attr('value'));
        self::assertSame('/projekte', $view->filterXPath('//form//input[@name="target"]')->attr('value'));
        self::assertCount(0, $view->filterXPath('//input[@type="password" and @value]'));
        self::getContainer()->get(AppSettingsService::class)->updateSettings(['registration_enabled' => false]);
        $client->request('GET', '/register');
        self::assertResponseStatusCodeSame(404);
    }

    #[DataProvider('themes')]
    public function testServerBlocksRespectVisibilitySiteFiltersAndSavedSettings(string $theme): void
    {
        $client = self::createClient();
        $this->configure($theme);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $site = $em->getRepository(Site::class)->findOneBy(['host' => 'localhost']);
        $other = new Site('Foreign CMS', 'foreign-cms.example.test');
        $em->persist($other);
        $em->flush();
        $admin = $this->createUser('cms-server-admin@example.test', UserType::Admin);
        foreach ([[$site, 'Owned public server', true, 'minecraft'], [$site, 'OTHER-GAME-SERVER', true, 'cs2'], [$site, 'HIDDEN-SERVER', false, 'minecraft'], [$other, 'FOREIGN-SERVER', true, 'minecraft']] as [$owner, $name, $public, $game]) {
            $em->persist(new PublicServer($owner->getId(), $name, 'Community', $game, '192.0.2.5', 27015, 'a2s', 60, $admin, visiblePublic: $public, visibleLoggedIn: true, notesInternal: 'PRIVATE-NOTES', statusCache: ['status' => 'running', 'players' => 4, 'max_players' => 20], lastCheckedAt: new \DateTimeImmutable()));
        }
        $page = $em->getRepository(CmsPage::class)->findOneBy(['site' => $site, 'slug' => 'startseite']);
        $block = new CmsBlock($page, 'server_list', json_encode(['game' => 'minecraft', 'limit' => 1, 'show_players' => false, 'show_join_button' => false], JSON_THROW_ON_ERROR), 20);
        $em->persist($block);
        $em->flush();
        $view = $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $view->filterXPath('//*[@data-cms-block="server_list"]//tbody//tr[@data-server-id]'));
        self::assertStringContainsString('Owned public server', $view->filterXPath('//*[@data-cms-block="server_list"]')->text());
        self::assertCount(0, $view->filterXPath('//*[@data-cms-block="server_list"]//a[starts-with(@href,"steam:")]'));
        self::assertCount(0, $view->filterXPath('//*[@data-cms-block="server_list"]//*[@data-status-field="players_wrap"]'));
        foreach (['HIDDEN-SERVER', 'FOREIGN-SERVER', 'OTHER-GAME-SERVER', 'PRIVATE-NOTES'] as $private) {
            self::assertStringNotContainsString($private, (string) $client->getResponse()->getContent());
        }
    }

    public function testNavigationDeduplicatesConfiguredLinksAndHonorsDisabledModules(): void
    {
        $client = self::createClient();
        $this->configure('clan');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $site = $em->getRepository(Site::class)->findOneBy(['host' => 'localhost']);
        $settings = $em->getRepository(CmsSiteSettings::class)->findOneBy(['site' => $site]);
        $settings->setModuleTogglesJson(array_fill_keys(array_keys(CmsSettingsProvider::DEFAULT_MODULE_TOGGLES), false));
        $links = [];
        foreach (['/projekte', '/projekte', '/blog', '/teams', '/events', '/forum', '/servers', 'javascript:alert(1)', 'https://example.test/community'] as $url) {
            $links[] = ['label' => 'Configured link', 'url' => $url, 'external' => str_starts_with($url, 'https:')];
        }
        $settings->setHeaderLinksJson($links);
        $em->flush();
        $view = $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $view->filterXPath('//*[@id="cms-site-navigation"]//a[@href="/projekte"]'));
        self::assertCount(2, $view->filterXPath('//*[@id="cms-site-navigation"]//a'));
        self::assertSame('noopener noreferrer', $view->filterXPath('//*[@id="cms-site-navigation"]//a[@target="_blank"]')->attr('rel'));
        self::assertCount(0, $view->filterXPath('//a[starts-with(@href,"javascript:")]'));
    }

    public function testAdminCanSwitchThemesWithoutChangingSavedBlocks(): void
    {
        $client = self::createClient();
        $this->configure('private');
        $this->loginAsRole($client, 'admin');
        $view = $client->request('GET', '/admin/cms/settings');
        self::assertResponseIsSuccessful();
        self::assertCount(10, $view->filterXPath('//input[@name="active_theme"]'));
        $data = ['active_theme' => 'hosting', 'header_links_json' => '[]', 'footer_links_json' => '[]', '_token' => $view->filterXPath('//form[@action="/admin/cms/settings"]//input[@name="_token"]')->attr('value')];
        foreach (array_keys(CmsSettingsProvider::DEFAULT_MODULE_TOGGLES) as $key) {
            $data['toggle_'.$key] = '1';
        }
        $client->request('POST', '/admin/cms/settings', array_replace($data, ['_token' => 'invalid']));
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', '/admin/cms/settings', $data);
        self::assertResponseRedirects('/admin/cms/settings?saved=1');
        $view = $client->request('GET', '/projekte');
        self::assertResponseIsSuccessful();
        self::assertSame('hosting', $view->filterXPath('//body')->attr('data-cms-theme'));
        self::assertStringContainsString('KEPT-CMS-PROJECT', (string) $client->getResponse()->getContent());
        self::assertCount(1, $view->filterXPath('//*[@data-cms-block="rich_text"]//p[normalize-space(.)="KEPT-CMS-PROJECT"]'));
    }

    private function configure(string $theme): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $site = $em->getRepository(Site::class)->findOneBy(['host' => 'localhost']);
        $settings = $em->getRepository(CmsSiteSettings::class)->findOneBy(['site' => $site]);
        $settings->setActiveTheme($theme);
        $settings->setModuleTogglesJson(CmsSettingsProvider::DEFAULT_MODULE_TOGGLES);
        $page = new CmsPage($site, 'Projects', 'projekte', true);
        $block = new CmsBlock($page, 'rich_text', '', 10);
        $block->setVersion(2);
        $block->setPayloadJson(['content' => '<p>KEPT-CMS-PROJECT</p>']);
        $em->persist($page);
        $em->persist($block);
        $em->persist(new CmsPage($site, 'DRAFT-CMS-SECRET', 'unpublished-secret', false));
        $em->flush();
    }
}
