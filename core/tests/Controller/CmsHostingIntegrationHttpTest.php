<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Cms\Application\CmsSettingsProvider;
use App\Module\Core\Domain\Entity\CmsBlock;
use App\Module\Core\Domain\Entity\CmsPage;
use App\Module\Core\Domain\Entity\CmsSiteSettings;
use App\Module\Core\Domain\Entity\ModuleSetting;
use App\Module\Core\Domain\Entity\ShopCategory;
use App\Module\Core\Domain\Entity\ShopProduct;
use App\Module\Core\Domain\Entity\Site;
use Doctrine\ORM\EntityManagerInterface;

final class CmsHostingIntegrationHttpTest extends AbstractWebTestCase
{
    /** @var array<string,?bool> */
    private array $previous = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSite();
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        foreach (['cms','shop','billing'] as $key) {
            $setting = $em->getRepository(ModuleSetting::class)->findOneBy(['moduleKey' => $key]);
            $this->previous[$key] = $setting?->isEnabled();
            if (null === $setting) {
                $setting = new ModuleSetting($key, '1', true);
                $em->persist($setting);
            }
            $setting->setEnabled(true);
        }
        $this->settings()->setActiveTheme('hosting');
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

    public function testSavedHostingMenusExposeEnabledModulesAndKeepCustomLinks(): void
    {
        $client = self::createClient();
        $settings = $this->settings();
        $settings->setHeaderLinksJson([['label' => 'Projects','url' => '/projects','external' => false],['label' => 'Order','url' => '/shop','external' => false]]);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $view = $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        foreach (['/projects','/shop','/teams','/events','/blog','/forum','/kontakt'] as $url) {
            self::assertCount(1, $view->filterXPath('//*[@id="cms-site-navigation"]//a[@href="'.$url.'"]'), $url);
        }
        $this->settings()->setModuleTogglesJson(array_replace(CmsSettingsProvider::DEFAULT_MODULE_TOGGLES, ['team' => false,'events' => false,'blog' => false,'forum' => false]));
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $view = $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        foreach (['/teams','/events','/blog','/forum'] as $url) {
            self::assertCount(0, $view->filterXPath('//*[@id="cms-site-navigation"]//a[@href="'.$url.'"]'));
        }
        self::assertCount(1, $view->filterXPath('//*[@id="cms-site-navigation"]//a[@href="/shop"]'));
    }

    public function testHomeShopIsBoundedAndUsesOnlyPublicProductsOfTheCurrentWebsite(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $instance = $this->seedInstance();
        $site = $this->site();
        $foreign = new Site('Foreign', 'foreign-hosting.test');
        $em->persist($foreign);
        $em->flush();
        $own = new ShopCategory($site->getId(), 'Hosting', 'hosting');
        $other = new ShopCategory($foreign->getId(), 'FOREIGN-CATEGORY', 'foreign');
        $hidden = new ShopCategory($site->getId(), 'Hidden', 'hidden');
        $hidden->configure('Hidden category', false);
        foreach ([$own,$other,$hidden] as $category) {
            $em->persist($category);
        }
        for ($i = 0;$i < 8;++$i) {
            $em->persist($this->product($site, $own, 'Product '.$i, $instance));
        }
        $private = $this->product($site, $own, 'PRIVATE-PRODUCT', $instance);
        $private->setIsPublicActive(false);
        $em->persist($private);
        $hiddenProduct = $this->product($site, $hidden, 'HIDDEN-PRODUCT', $instance);
        $em->persist($hiddenProduct);
        $foreignProduct = $this->product($foreign, $other, 'FOREIGN-PRODUCT', $instance);
        $em->persist($foreignProduct);
        $invalid = $this->product($site, $other, 'WRONG-CATEGORY-SITE', $instance);
        $em->persist($invalid);
        $em->flush();
        $view = $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertCount(6, $view->filterXPath('//*[@data-cms-home-shop]//*[@data-shop-product]'));
        foreach (['PRIVATE-PRODUCT','HIDDEN-PRODUCT','FOREIGN-PRODUCT','WRONG-CATEGORY-SITE'] as $secret) {
            self::assertStringNotContainsString($secret, (string)$client->getResponse()->getContent());
        }
        $view = $client->request('GET', '/shop');
        self::assertResponseIsSuccessful();
        self::assertCount(8, $view->filterXPath('//*[@data-shop-product]'));
        foreach ([$hiddenProduct,$foreignProduct,$invalid] as $product) {
            $client->request('GET', '/shop/products/'.$product->getId());
            self::assertResponseStatusCodeSame(404);
        }
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $page = $em->getRepository(CmsPage::class)->findOneBy(['site' => $this->site(),'slug' => 'startseite']);
        $block = new CmsBlock($page, 'shop_products', '', 10);
        $block->setVersion(2);
        $block->setPayloadJson(['limit' => 2]);
        $em->persist($block);
        $em->flush();
        $view = $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $view->filterXPath('//*[@data-cms-home-shop]'));
        self::assertCount(2, $view->filterXPath('//*[@data-shop-product]'));
    }

    public function testDisablingShopHidesItsEntryAndHomepageContent(): void
    {
        $client = self::createClient();
        foreach (['shop','billing'] as $module) {
            $em = self::getContainer()->get(EntityManagerInterface::class);
            $setting = $em->getRepository(ModuleSetting::class)->findOneBy(['moduleKey' => $module]);
            $setting->setEnabled(false);
            $em->flush();
            $view = $client->request('GET', '/');
            self::assertResponseIsSuccessful();
            self::assertCount(0, $view->filterXPath('//*[@data-cms-home-shop]'));
            self::assertCount(0, $view->filterXPath('//*[@id="cms-site-navigation"]//a[@href="/shop"]'));
            $client->request('GET', '/shop');
            self::assertResponseStatusCodeSame(404);
            $em = self::getContainer()->get(EntityManagerInterface::class);
            $em->getRepository(ModuleSetting::class)->findOneBy(['moduleKey' => $module])->setEnabled(true);
            $em->flush();
        }
    }

    public function testCookieActionsAreDialogsAndLegacyRoutesKeepTheLanguage(): void
    {
        $client = self::createClient();
        $this->settings()->setFooterLinksJson([['label' => 'Policy','url' => '/cookies','external' => false],['label' => 'Preferences','url' => '/cookie-preferences','external' => false]]);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $view = $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $view->filterXPath('//dialog[@id="cookie-settings-modal"]'));
        self::assertCount(1, $view->filterXPath('//footer[contains(@class,"cms-footer")]//button[@data-cookie-policy]'));
        self::assertCount(1, $view->filterXPath('//footer[contains(@class,"cms-footer")]//button[@data-cookie-open]'));
        self::assertCount(0, $view->filterXPath('//footer[contains(@class,"cms-footer")]//a[@href="/cookies" or @href="/cookie-preferences"]'));
        self::assertCount(5, $view->filterXPath('//*[@id="cookie-policy-content"]//tbody/tr'));
        foreach (['/cookies' => 'policy','/cookie-richtlinie' => 'policy','/cookie-preferences' => 'settings','/cookie-einstellungen' => 'settings'] as $path => $key) {
            $client->request('GET', $path.'?lang=en');
            self::assertResponseRedirects('/?lang=en#cookie-'.$key);
        }
    }

    private function site(): Site
    {
        $site = self::getContainer()->get(EntityManagerInterface::class)->getRepository(Site::class)->findOneBy(['host' => 'localhost']);
        self::assertInstanceOf(Site::class, $site);
        return $site;
    }
    private function settings(): CmsSiteSettings
    {
        $settings = self::getContainer()->get(EntityManagerInterface::class)->getRepository(CmsSiteSettings::class)->findOneBy(['site' => $this->site()]);
        self::assertInstanceOf(CmsSiteSettings::class, $settings);
        return $settings;
    }
    private function product(Site $site, ShopCategory $category, string $name, \App\Module\Core\Domain\Entity\Instance $instance): ShopProduct
    {
        $product = new ShopProduct($site->getId(),$category,$name,'Description',590,$instance->getTemplate(),$instance->getNode(),1,2,20);
        $product->setIsPublicActive(true);
        return $product;
    }
}
