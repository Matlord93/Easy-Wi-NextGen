<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Entity\ModuleSetting;
use Doctrine\ORM\EntityManagerInterface;

final class AdminWorkspaceHttpTest extends AbstractWebTestCase
{
    public function testSetupGuideIsReadOnlyAndAdminOnly(): void
    {
        $this->seedSite();
        $client = static::createClient();
        $this->loginAsRole($client, 'admin');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $before = [$em->getRepository(User::class)->count([]), $em->getRepository(ModuleSetting::class)->count([])];
        $client->request('GET', '/admin/getting-started');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        foreach (['aw-setup-grid', 'aw-setup-card', 'Menü durchsuchen', '/admin/nodes', '/admin/billing/settings', 'admin-workspace.css', 'panel-theme.js'] as $expected) {
            self::assertStringContainsString($expected, $html);
        }
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertSame($before, [$em->getRepository(User::class)->count([]), $em->getRepository(ModuleSetting::class)->count([])]);
        $client->request('POST', '/admin/getting-started');
        self::assertResponseStatusCodeSame(405);
    }

    public function testDashboardUsesTheSharedWorkspaceDesignWithoutTheSetupGuide(): void
    {
        $this->seedSite();
        $client = static::createClient();
        $this->loginAsRole($client, 'admin');
        $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        foreach (['admin-workspace.css', 'js/admin-workspace.js', 'infrastructure.css', 'data-admin-nav-search'] as $expected) {
            self::assertStringContainsString($expected, $html);
        }
        self::assertStringNotContainsString('class="aw-context"', $html);
        self::assertStringContainsString('panel-theme.js', $html);
    }

    public function testExistingAdminFormsKeepTheirRoutesAndFields(): void
    {
        $this->seedSite();
        $client = static::createClient();
        $this->loginAsRole($client, 'admin');
        $client->request('GET', '/admin/settings');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('admin-workspace.css', $html);
        self::assertStringContainsString('class="aw-context"', $html);
        self::assertStringContainsString('name="site_title"', $html);
        self::assertStringContainsString('method="post" action="/admin/settings"', $html);
        self::assertStringNotContainsString('localStorage.setItem(\'theme\'', $html);
    }

    public function testCmsFormRemainsAddressableAfterValidationAndRepeatedSave(): void
    {
        $this->seedSite();
        $client = static::createClient();
        $this->loginAsRole($client, 'admin');
        $client->request('POST', '/admin/cms/pages', ['title' => '', 'slug' => ''], server: ['HTTP_HX_REQUEST' => 'true']);
        self::assertResponseStatusCodeSame(400);
        self::assertCount(1, $client->getCrawler()->filterXPath('//*[@id="cms-page-form" and @data-admin-validation]'));
        self::assertCount(1, $client->getCrawler()->filterXPath('//*[@id="cms-page-form"]//*[contains(@class, "ng-alert--danger")]'));
        foreach (['first-draft', 'second-draft'] as $slug) {
            $client->request('POST', '/admin/cms/pages', ['title' => $slug, 'slug' => $slug], server: ['HTTP_HX_REQUEST' => 'true']);
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('HX-Trigger', 'cms-pages-changed');
            self::assertCount(1, $client->getCrawler()->filterXPath('//*[@id="cms-page-form"]'));
            self::assertCount(1, $client->getCrawler()->filterXPath('//*[@id="cms-page-form"]//form[@hx-target="#cms-page-form"]'));
        }
        $client->request('GET', '/admin/cms/pages/table', server: ['HTTP_HX_REQUEST' => 'true']);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $client->getCrawler()->filterXPath('//*[@id="cms-pages-table" and @hx-trigger="cms-pages-changed from:body"]'));
        self::assertStringContainsString('first-draft', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('second-draft', (string) $client->getResponse()->getContent());
    }

    public function testCustomerCannotOpenAdminSetup(): void
    {
        $this->seedSite();
        $client = static::createClient();
        $this->loginAsRole($client, 'customer');
        $client->request('GET', '/admin/getting-started');
        self::assertContains($client->getResponse()->getStatusCode(), [401, 403]);
        self::assertStringNotContainsString('aw-setup-card', (string) $client->getResponse()->getContent());
    }
}
