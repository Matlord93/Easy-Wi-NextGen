<?php

declare(strict_types=1);

namespace App\Tests\Module\PanelAdmin;

use App\Module\PanelAdmin\Application\AdminWorkspaceGuide;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AdminWorkspaceGuideTest extends KernelTestCase
{
    public function testEveryGuideLinksRealGetRoutesAndHasBothTranslations(): void
    {
        self::bootKernel();
        $guide = self::getContainer()->get(AdminWorkspaceGuide::class);
        $router = self::getContainer()->get(RouterInterface::class);
        $translator = self::getContainer()->get(TranslatorInterface::class);
        foreach ($guide->all() as $section) {
            self::assertNotEmpty($section['steps']);
            foreach (['de', 'en'] as $locale) {
                foreach (['title', 'intro'] as $field) {
                    $key = 'workspace.guide.'.$section['key'].'.'.$field;
                    self::assertNotSame($key, $translator->trans($key, [], 'admin_workspace', $locale));
                }
            }
            foreach ($section['steps'] as $step) {
                self::assertNotNull($step['href']);
                self::assertStringStartsWith('/admin/', $step['href']);
                self::assertSame($step['route'], $router->match($step['href'])['_route']);
                foreach (['de', 'en'] as $locale) {
                    foreach (['title', 'body'] as $field) {
                        $key = $step['key'].'.'.$field;
                        self::assertNotSame($key, $translator->trans($key, [], 'admin_workspace', $locale));
                    }
                }
            }
        }
    }

    public function testContextFollowsTheActualAdminNavigation(): void
    {
        self::bootKernel();
        $guide = self::getContainer()->get(AdminWorkspaceGuide::class);
        foreach (['nodes' => 'nodes', 'bootstrap-tokens' => 'nodes', 'mailboxes' => 'mail', 'game-instances' => 'games', 'proxmox' => 'proxmox', 'kvm' => 'kvm', 'billing' => 'billing', 'cms-pages' => 'cms', 'settings' => 'general'] as $nav => $context) {
            self::assertSame($context, $guide->context($nav)['key']);
        }
        self::assertSame('admin_ts6_nodes_index', $guide->context('ts6-servers')['steps'][0]['route']);
        self::assertSame('admin_ts6_servers_index', $guide->context('ts6-servers')['steps'][1]['route']);
        self::assertSame('admin_sinusbot_nodes_index', $guide->context('sinusbot')['steps'][0]['route']);
        self::assertSame('admin_sinusbot_nodes_index', $guide->context('sinusbot')['steps'][1]['route']);
        self::assertSame('general', $guide->context('extension-without-guide')['key']);
    }
}
