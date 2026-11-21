<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Application\Contracts\RentalCapacity;
use App\Module\Core\Domain\Entity\ModuleSetting;
use App\Module\Core\Domain\Entity\RentalCapacityPool;
use App\Module\Core\Domain\Entity\ShopCategory;
use App\Module\Core\Domain\Entity\ShopProduct;
use App\Module\Core\Domain\Entity\ShopProductVariant;
use App\Module\Core\Domain\Entity\Site;
use App\Module\Core\Domain\Entity\Ts3Node;
use App\Module\Core\Domain\Entity\Ts6Node;
use App\Module\Core\Domain\Enum\ShopProductKind;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;

final class AdminShopWorkspaceHttpTest extends AbstractWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSite();
        self::bootKernel();
        $this->resetShop();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new ModuleSetting('shop', '1', true));
        $em->flush();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (null !== self::$kernel) {
            $this->resetShop();
        }
        parent::tearDown();
    }

    private function resetShop(): void
    {
        $db = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $db->executeStatement("DELETE FROM cms_blocks WHERE type='shop_products'");
        foreach (['registrar_operations', 'registrar_contacts', 'rental_capacity_leases', 'wallet_entries', 'rental_notices', 'shop_domain_terms', 'shop_contracts', 'payment_checkouts', 'wallet_topups', 'rental_credits', 'rental_accounts', 'shop_orders', 'shop_rentals', 'shop_product_variants', 'shop_products', 'shop_categories', 'shop_coupons', 'rental_capacity_pools'] as $table) {
            $db->executeStatement('DELETE FROM '.$table);
        }
        $db->executeStatement("DELETE FROM module_settings WHERE module_key IN ('cms', 'billing', 'shop')");
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    public static function productKinds(): iterable
    {
        foreach (ShopProductKind::cases() as $kind) {
            yield $kind->value => [$kind->value];
        }
    }

    #[DataProvider('productKinds')]
    public function testDedicatedEditorsSaveOnlyTheSelectedKindAndRetainErrors(string $kind): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'admin');
        $instance = $this->seedInstance();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $site = $em->getRepository(Site::class)->findOneBy(['host' => 'localhost']);
        $category = new ShopCategory($site->getId(), 'Shop fixture', 'shop-fixture');
        $em->persist($category);
        $voice = 'teamspeak3' === $kind ? new Ts3Node('Voice fixture', $instance->getNode(), 'https://voice.example.test', 'encrypted', 'https://example.test/archive', '/srv/voice', 'voice', 'voice') : new Ts6Node('Voice fixture', $instance->getNode(), 'https://voice.example.test', 'encrypted', 'https://example.test/archive', '/srv/voice', 'voice', 'voice');
        $em->persist($voice);
        $cluster = new \App\Module\Nodes\Domain\Entity\ProxmoxCluster('Shop PVE', new \App\Module\Nodes\Domain\Proxmox\ProxmoxConnection('https://pve.shop.test:8006', 'shop@pve!fixture', 'synthetic'), self::getContainer()->get(\App\Module\Core\Application\SecretsCrypto::class));
        $em->persist($cluster);
        $em->flush();
        $jobsBefore = (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM jobs');
        $url = '/admin/shop/rental-products/'.$kind.'/new';
        $view = $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $view->filterXPath('//form[@data-shop-editor="'.$kind.'"]'));
        foreach (['template_id' => ['gameserver'], 'kvm_template' => ['kvm'], 'proxmox_cluster_id' => ['proxmox'], 'address_mode' => ['kvm', 'proxmox'], 'php_version' => ['webspace'], 'registrar' => ['domain'], 'slots' => ['gameserver', 'teamspeak3', 'teamspeak6'], 'cpu' => ['gameserver', 'kvm', 'proxmox']] as $name => $kinds) {
            self::assertCount(in_array($kind, $kinds, true) ? 1 : 0, $view->filterXPath('//form[@data-shop-editor]//*[@name="'.$name.'"]'));
        }
        $values = ['name' => 'Shop '.$kind, 'description' => 'Own description', 'category_id' => $category->getId(), 'price' => '10.00', 'node_id' => $instance->getNode()->getId(), 'template_id' => $instance->getTemplate()->getId(), 'ts3_node_id' => $voice->getId(), 'ts6_node_id' => $voice->getId(), 'slots' => '64', 'kvm_template' => 'ubuntu--26.04-v1', 'network' => 'default', 'network_type' => 'network', 'php_version' => '8.5', 'registrar' => 'manual', 'tld' => 'de', 'term_months' => '12', 'active' => '1', 'public' => '1', 'customer' => '1', 'kind' => $kind, 'product_id' => '0'];
        $values['_token'] = $view->filterXPath('//form[@data-shop-editor]//input[@name="_token"]')->attr('value');
        $values['address_mode'] = 'pool';
        $values += ['proxmox_cluster_id' => $cluster->getId(), 'proxmox_node' => 'pve-a', 'proxmox_template_id' => '900'];
        $client->request('POST', $url, array_replace($values, ['price' => 'bad-price', 'name' => 'Keep this name', 'public' => '0']));
        self::assertResponseStatusCodeSame(422);
        $failed = $client->getCrawler();
        self::assertSame('Keep this name', $failed->filterXPath('//input[@name="name"]')->attr('value'));
        self::assertNull($failed->filterXPath('//input[@name="public"]')->attr('checked'));
        $client->request('POST', $url, $values);
        self::assertResponseStatusCodeSame(303);
        $edit = $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Produkt gespeichert', $edit->text());
        $saved = self::getContainer()->get(EntityManagerInterface::class)->getRepository(ShopProduct::class)->findOneBy(['name' => 'Shop '.$kind]);
        self::assertSame($kind, $saved->getKind()->value);
        self::assertSame(1000, $saved->getPriceMonthlyCents());
        self::assertSame('Own description', $saved->getDescription());
        self::assertNull($saved->getImageUrl());
        if (!in_array($kind, ['domain', 'proxmox'], true)) {
            self::assertSame($instance->getNode()->getId(), $saved->getProvisioningNode()->getId());
        }
        self::assertSame('gameserver' === $kind ? $instance->getTemplate()->getId() : null, $saved->getGameTemplate()?->getId());
        if ('gameserver' === $kind) {
            self::assertSame(64, $saved->getGameserverSlots());
        }
        self::assertSame(in_array($kind, ['kvm', 'proxmox'], true) ? 'pool' : null, $saved->getProvisioningOptions()['address_mode'] ?? null);
        self::assertSame('proxmox' === $kind ? 900 : null, $saved->getProvisioningOptions()['proxmox_template_id'] ?? null);
        self::assertSame($jobsBefore, (int) self::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne('SELECT COUNT(*) FROM jobs'));
    }

    public function testMissingDisabledAndExhaustedPoolsBlockCheckoutIncludingSelectedVariants(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'admin');
        $instance = $this->seedInstance();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $site = $em->getRepository(Site::class)->findOneBy(['host' => 'localhost']);
        $category = new ShopCategory($site->getId(), 'Games', 'games');
        $product = new ShopProduct($site->getId(), $category, 'Capacity fixture', '', 1000, $instance->getTemplate(), $instance->getNode(), 4, 8, 10);
        $variant = new ShopProductVariant($product, 'LARGE-CAPACITY', 'Large', 2000, 8, 16, 20);
        foreach ([$category, $product, $variant] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $id = $product->getId();
        $url = '/admin/shop/rental-products/gameserver/'.$id.'/capacity';
        $view = $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('noch kein Verkaufskapazitätspool', $view->text());
        $token = $view->filterXPath('//input[@name="_token"]')->attr('value');
        $client->request('GET', '/shop/products/'.$id);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $client->getCrawler()->filterXPath('//button[contains(text(),"Verbindlich mieten")]'));
        $values = ['_token' => $token, 'cpu' => '4', 'ram_mib' => '8192', 'disk_mib' => '10240', 'systems' => '1', 'enabled' => '1'];
        $client->request('POST', $url, array_replace($values, ['_token' => 'invalid']));
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', $url, $values);
        self::assertResponseRedirects($url.'?saved=1', 303);
        $client->request('GET', '/shop/products/'.$id);
        self::assertStringContainsString('Kapazität verfügbar', $client->getCrawler()->text());
        self::assertStringContainsString('Für eine Bestellung ist ein Kundenkonto erforderlich', $client->getCrawler()->text());
        $client->request('GET', '/shop/products/'.$id.'?variant_id='.$variant->getId());
        self::assertStringContainsString('Derzeit ausverkauft', $client->getCrawler()->text());
        self::assertCount(0, $client->getCrawler()->filterXPath('//button[contains(text(),"Verbindlich mieten")]'));
        $client->request('POST', $url, array_replace($values, ['enabled' => '0']));
        $client->request('GET', $url);
        self::assertStringContainsString('ist deaktiviert', $client->getCrawler()->text());
        $client->request('GET', '/shop/products/'.$id);
        $token = $client->getCrawler()->filterXPath('//input[@name="_token"]')->attr('value');
        $client->request('POST', '/shop/products/'.$id, ['_token' => $token, 'stage' => 'order', 'rental_terms' => '1']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('nicht zur Bestellung freigegeben', $client->getCrawler()->text());
        self::assertSame(0, (int) self::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne('SELECT COUNT(*) FROM shop_contracts'));
    }

    public function testCapacityUpdatesPreserveOtherResourcesAndGameserverPortsAreRequired(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'admin');
        $instance = $this->seedInstance();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $instance->getTemplate()->setRequiredPorts([['name' => 'game', 'protocol' => 'udp']]);
        $site = $em->getRepository(Site::class)->findOneBy(['host' => 'localhost']);
        $category = new ShopCategory($site->getId(), 'Games', 'games');
        $product = new ShopProduct($site->getId(), $category, 'Ports fixture', '', 1000, $instance->getTemplate(), $instance->getNode(), 1, 1, 1);
        $pool = new RentalCapacityPool('agent:'.$instance->getNode()->getId(), ['cpu' => 8, 'ram_mib' => 8192, 'disk_mib' => 10240, 'systems' => 10, 'slots' => 80]);
        foreach ([$category, $product, $pool] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $capacity = self::getContainer()->get(RentalCapacity::class);
        self::assertSame(['ports' => 1], $capacity->inspect($product)['missing']);
        $url = '/admin/shop/rental-products/gameserver/'.$product->getId().'/capacity';
        $view = $client->request('GET', $url);
        $token = $view->filterXPath('//input[@name="_token"]')->attr('value');
        $client->request('POST', $url, ['_token' => $token, 'cpu' => '8', 'ram_mib' => '8192', 'disk_mib' => '10240', 'systems' => '10', 'ports' => '10', 'enabled' => '1', 'slots' => '9999', 'pool' => 'agent:foreign']);
        self::assertResponseStatusCodeSame(303);
        $pool = self::getContainer()->get(EntityManagerInterface::class)->find(RentalCapacityPool::class, $pool->getId());
        self::assertSame(80, $pool->getLimits()['slots']);
        self::assertSame(10, $pool->getLimits()['ports']);
    }

    public function testGameProductSelectsTheConfiguredNodeInstallDirectory(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'admin');
        $instance = $this->seedInstance();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $instance->getNode()->setMetadata(['instance_base_dirs' => ['/mnt/server-disk', '/srv/second-disk']]);
        $site = $em->getRepository(Site::class)->findOneBy(['host' => 'localhost']);
        $category = new ShopCategory($site->getId(), 'Paths', 'paths');
        $product = new ShopProduct($site->getId(), $category, 'Game path fixture', '', 1000, $instance->getTemplate(), $instance->getNode(), 1, 1, 1);
        $em->persist($category);
        $em->persist($product);
        $em->flush();
        $id = $product->getId();
        $url = '/admin/shop/rental-products/gameserver/'.$id.'/edit';
        $view = $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('/mnt/server-disk', $view->text());
        $values = ['_token' => $view->filterXPath('//form[@data-shop-editor]//input[@name="_token"]')->attr('value'), 'kind' => 'gameserver', 'product_id' => (string) $id, 'name' => 'Game path fixture', 'category_id' => $category->getId(), 'price' => '10.00', 'node_id' => $instance->getNode()->getId(), 'template_id' => $instance->getTemplate()->getId(), 'cpu' => '1', 'ram' => '1', 'disk' => '1', 'instance_base_dir' => '/var/easywi', 'active' => '1'];
        $client->request('POST', $url, $values);
        self::assertResponseStatusCodeSame(422);
        $values['instance_base_dir'] = '/srv/second-disk';
        $client->request('POST', $url, $values);
        self::assertResponseStatusCodeSame(303);
        $saved = self::getContainer()->get(EntityManagerInterface::class)->find(ShopProduct::class, $id);
        self::assertSame('/srv/second-disk', $saved->getProvisioningOptions()['instance_base_dir']);
    }

    public function testKindListsUseSeparateBoundedPagesAndSearch(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'admin');
        $instance = $this->seedInstance();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $site = $em->getRepository(Site::class)->findOneBy(['host' => 'localhost']);
        $category = new ShopCategory($site->getId(), 'Inventory', 'inventory');
        $em->persist($category);
        for ($i = 0; $i < 26; ++$i) {
            $product = new ShopProduct($site->getId(), $category, sprintf('Inventory %02d', $i), '', 1000, $instance->getTemplate(), $instance->getNode(), 1, 1, 1);
            if (25 === $i) {
                $product->configureKind(ShopProductKind::Webspace, ['php_version' => '8.4']);
            }
            $em->persist($product);
        }
        $em->flush();
        $view = $client->request('GET', '/admin/shop/rental-products/gameserver');
        self::assertResponseIsSuccessful();
        self::assertCount(24, $view->filterXPath('//*[@data-shop-product]'));
        self::assertStringNotContainsString('Inventory 25', $view->text());
        $view = $client->request('GET', '/admin/shop/rental-products/gameserver?page=2');
        self::assertCount(1, $view->filterXPath('//*[@data-shop-product]'));
        $view = $client->request('GET', '/admin/shop/rental-products/webspace');
        self::assertCount(1, $view->filterXPath('//*[@data-shop-product]'));
        self::assertStringContainsString('Inventory 25', $view->text());
        $view = $client->request('GET', '/admin/shop/rental-products/gameserver?q=Inventory+03');
        self::assertCount(1, $view->filterXPath('//*[@data-shop-product]'));
        self::assertStringContainsString('Inventory 03', $view->text());
    }

    public function testDeletionPreservesOrderHistoryAndCapacityPools(): void
    {
        $client = self::createClient();
        $admin = $this->loginAsRole($client, 'admin');
        $instance = $this->seedInstance();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $site = $em->getRepository(Site::class)->findOneBy(['host' => 'localhost']);
        $category = new ShopCategory($site->getId(), 'Delete fixture', 'delete');
        $used = new ShopProduct($site->getId(), $category, 'Used product', '', 1000, $instance->getTemplate(), $instance->getNode(), 1, 1, 1);
        $unused = new ShopProduct($site->getId(), $category, 'Unused product', '', 1000, $instance->getTemplate(), $instance->getNode(), 1, 1, 1);
        $order = new \App\Module\Core\Domain\Entity\ShopOrder($em->find(\App\Module\Core\Domain\Entity\User::class, $admin->getId()), $used, \App\Module\Core\Domain\Enum\ShopOrderType::New, \App\Module\Core\Domain\Enum\ShopOrderStatus::Pending, 1, 1000, 0);
        $pool = new RentalCapacityPool('agent:'.$instance->getNode()->getId(), ['systems' => 1]);
        foreach ([$category, $used, $unused, $order, $pool] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        foreach ([$used, $unused] as $product) {
            $id = $product->getId();
            $view = $client->request('GET', '/admin/shop/rental-products/gameserver/'.$id.'/edit');
            $token = $view->filterXPath('//form[@action="/admin/shop/products/'.$id.'/delete"]//input[@name="_token"]')->attr('value');
            $client->request('POST', '/admin/shop/products/'.$id.'/delete', ['_token' => 'invalid']);
            self::assertResponseStatusCodeSame(403);
            $client->request('POST', '/admin/shop/products/'.$id.'/delete', ['_token' => $token]);
            self::assertResponseStatusCodeSame(303);
            $client->followRedirect();
            self::assertResponseIsSuccessful();
        }
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertSame(1, $em->getRepository(ShopProduct::class)->count(['name' => 'Used product']));
        self::assertSame(0, $em->getRepository(ShopProduct::class)->count(['name' => 'Unused product']));
        self::assertNotNull($em->find(RentalCapacityPool::class, $pool->getId()));
    }

    public function testEditorsAndCapacityRespectSiteKindAndRoleBoundaries(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'admin');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $site = new Site('Foreign', 'foreign-shop.test');
        $em->persist($site);
        $em->flush();
        $category = new ShopCategory($site->getId(), 'Foreign category', 'foreign');
        $product = new ShopProduct($site->getId(), $category, 'FOREIGN-SHOP-PRODUCT', '', 100, null, null, 1, 1, 1);
        $product->configureKind(ShopProductKind::Domain, ['term_months' => 12]);
        $em->persist($category);
        $em->persist($product);
        $em->flush();
        foreach (['edit', 'capacity'] as $action) {
            $client->request('GET', '/admin/shop/rental-products/domain/'.$product->getId().'/'.$action);
            self::assertResponseStatusCodeSame(404);
        }
        $client->request('GET', '/admin/shop/products');
        self::assertResponseIsSuccessful();
        self::assertCount(count(ShopProductKind::cases()), $client->getCrawler()->filterXPath('//*[@data-shop-kind]'));
        self::assertCount(1, $client->getCrawler()->filterXPath('//*[@data-shop-kind="proxmox"]'));
        self::assertStringNotContainsString('FOREIGN-SHOP-PRODUCT', $client->getCrawler()->text());
        $client->getCookieJar()->clear();
        $this->loginAsRole($client, 'customer');
        foreach (['/admin/shop/products', '/admin/shop/rental-products/gameserver', '/admin/shop/rental-products/gameserver/new'] as $url) {
            $client->request('GET', $url);
            self::assertContains($client->getResponse()->getStatusCode(), [401, 403]);
        }
    }
}
