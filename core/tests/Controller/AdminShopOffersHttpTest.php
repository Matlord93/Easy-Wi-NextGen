<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Domain\Entity\ShopCategory;
use App\Module\Core\Domain\Entity\ShopCoupon;
use App\Module\Core\Domain\Entity\ShopProduct;
use App\Module\Core\Domain\Entity\ShopProductVariant;
use App\Module\Core\Domain\Entity\Site;
use App\Module\Core\Domain\Enum\ShopProductKind;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;

final class AdminShopOffersHttpTest extends AbstractWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSite();
        self::bootKernel();
        $this->clearShop();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (null !== self::$kernel) {
            $this->clearShop();
        }
        parent::tearDown();
    }

    private function clearShop(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $db = $em->getConnection();
        $db->executeStatement("DELETE FROM cms_blocks WHERE type='shop_products'");
        foreach (['registrar_operations', 'registrar_contacts', 'rental_capacity_leases', 'wallet_entries', 'rental_notices', 'shop_domain_terms', 'shop_contracts', 'payment_checkouts', 'wallet_topups', 'rental_credits', 'rental_accounts', 'shop_orders', 'shop_rentals', 'shop_product_variants', 'shop_products', 'shop_categories', 'shop_coupons', 'rental_capacity_pools'] as $table) {
            $db->executeStatement('DELETE FROM '.$table);
        }
        $em->clear();
    }

    private function product(ShopProductKind $kind): ShopProduct
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $site = $em->getRepository(Site::class)->findOneBy(['host' => 'localhost']);
        $category = new ShopCategory($site->getId(), 'Offers', 'offers-'.bin2hex(random_bytes(3)));
        $product = new ShopProduct($site->getId(), $category, 'Offer '.$kind->value, '', 500, null, null, 2, 4, 20);
        $product->configureKind($kind, ['slots' => 32]);
        $em->persist($category);
        $em->persist($product);
        $em->flush();

        return $product;
    }

    public static function kinds(): iterable
    {
        foreach (ShopProductKind::cases() as $kind) {
            yield $kind->value => [$kind];
        }
    }

    #[DataProvider('kinds')]
    public function testVariantsUseTheProductKindAndRetainInvalidInput(ShopProductKind $kind): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'admin');
        $product = $this->product($kind);
        $url = '/admin/shop/products/'.$product->getId().'/variants/new';
        $view = $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        foreach (['cpu' => ['gameserver', 'kvm'], 'ram' => ['gameserver', 'kvm'], 'disk' => ['gameserver', 'kvm', 'webspace'], 'slots' => ['teamspeak3', 'teamspeak6']] as $field => $kinds) {
            self::assertCount(in_array($kind->value, $kinds, true) ? 1 : 0, $view->filterXPath('//form[@data-variant-editor]//*[@name="'.$field.'"]'));
        }
        $token = $view->filterXPath('//input[@name="_token"]')->attr('value');
        $payload = ['_token' => $token, 'sku' => 'NEW-'.$kind->value, 'name' => 'Large', 'price' => '15.00', 'cpu' => '4', 'ram' => '8', 'disk' => '40', 'slots' => '64', 'active' => '1'];
        $client->request('POST', $url, array_replace($payload, ['price' => 'invalid', 'name' => 'Keep variant', 'active' => '0']));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Keep variant', $client->getCrawler()->filterXPath('//input[@name="name"]')->attr('value'));
        self::assertNull($client->getCrawler()->filterXPath('//input[@name="active"]')->attr('checked'));
        $client->request('POST', $url, array_replace($payload, ['_token' => 'invalid']));
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', $url, $payload);
        self::assertResponseStatusCodeSame(303);
        $edit = $client->followRedirect();
        self::assertResponseIsSuccessful();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $variant = $em->getRepository(ShopProductVariant::class)->findOneBy(['product' => $product->getId()]);
        self::assertSame(1500, $variant->getPriceMonthlyCents());
        self::assertSame(in_array($kind->value, ['gameserver', 'kvm'], true) ? 4 : 2, $variant->getCpuLimit());
        self::assertSame(in_array($kind->value, ['gameserver', 'kvm', 'webspace'], true) ? 40 : 20, $variant->getDiskLimit());
        self::assertSame(in_array($kind->value, ['teamspeak3', 'teamspeak6'], true) ? 64 : null, $variant->getSlots());
        $id = $variant->getId();
        $editUrl = '/admin/shop/products/'.$product->getId().'/variants/'.$id.'/edit';
        $payload['_token'] = $edit->filterXPath('//input[@name="_token"]')->attr('value');
        $payload['active'] = '0';
        $client->request('POST', $editUrl, $payload);
        self::assertResponseStatusCodeSame(303);
        self::assertFalse(self::getContainer()->get(EntityManagerInterface::class)->find(ShopProductVariant::class, $id)->isActive());
    }

    public function testInvalidVoiceSlotsDoNotPartiallyChangeAnExistingVariant(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'admin');
        $product = $this->product(ShopProductKind::Teamspeak6);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $variant = new ShopProductVariant($product, 'VOICE-ORIGINAL', 'Original', 500, 2, 4, 20);
        $variant->setSlots(32);
        $em->persist($variant);
        $em->flush();
        $url = '/admin/shop/products/'.$product->getId().'/variants/'.$variant->getId().'/edit';
        $view = $client->request('GET', $url);
        $client->request('POST', $url, ['_token' => $view->filterXPath('//input[@name="_token"]')->attr('value'), 'sku' => 'VOICE-ORIGINAL', 'name' => 'Changed', 'price' => '25.00', 'slots' => '9999']);
        self::assertResponseStatusCodeSame(422);
        $db = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $row = $db->fetchAssociative('SELECT name, price_monthly_cents, slots FROM shop_product_variants WHERE id=?', [$variant->getId()]);
        self::assertSame('Original', $row['name']);
        self::assertSame(500, (int) $row['price_monthly_cents']);
        self::assertSame(32, (int) $row['slots']);
    }

    public function testCouponsKeepRedemptionsAndRejectInvalidDatesWithoutWriting(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'admin');
        $view = $client->request('GET', '/admin/shop/coupons/new');
        self::assertResponseIsSuccessful();
        $payload = ['_token' => $view->filterXPath('//input[@name="_token"]')->attr('value'), 'code' => 'SAVE20', 'discount_type' => 'percent', 'discount_value' => '20', 'minimum' => '0.00', 'maximum_uses' => '5', 'active' => '1'];
        $client->request('POST', '/admin/shop/coupons/new', $payload);
        self::assertResponseStatusCodeSame(303);
        $view = $client->followRedirect();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $coupon = $em->getRepository(ShopCoupon::class)->findOneBy(['code' => 'SAVE20']);
        $coupon->redeem(new \DateTimeImmutable());
        $em->flush();
        $id = $coupon->getId();
        $url = '/admin/shop/coupons/'.$id.'/edit';
        $payload['_token'] = $view->filterXPath('//input[@name="_token"]')->attr('value');
        $payload['discount_value'] = '30';
        $client->request('POST', $url, array_replace($payload, ['valid_from' => '2026-12-30', 'valid_until' => '2026-12-01', 'active' => '0']));
        self::assertResponseStatusCodeSame(422);
        self::assertNull($client->getCrawler()->filterXPath('//input[@name="active"]')->attr('checked'));
        $client->request('POST', $url, $payload);
        self::assertResponseStatusCodeSame(303);
        $coupon = self::getContainer()->get(EntityManagerInterface::class)->find(ShopCoupon::class, $id);
        self::assertSame(1, $coupon->getUsedCount());
        self::assertSame(30, $coupon->getDiscountValue());
        self::assertSame('SAVE20', $coupon->getCode());
        $client->request('GET', '/admin/shop/coupons');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $client->getCrawler()->filterXPath('//*[@data-shop-coupon]'));
    }

    public function testForeignProductsVariantsAndCouponsAreNotEditableAndListsAreSeparated(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'admin');
        $own = $this->product(ShopProductKind::Gameserver);
        $other = $this->product(ShopProductKind::Webspace);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $variant = new ShopProductVariant($other, 'OTHER-PRODUCT', 'OTHER-VARIANT', 100, 1, 1, 1);
        $foreign = new Site('Foreign', 'foreign-offers.test');
        $em->persist($foreign);
        $em->flush();
        $coupon = new ShopCoupon($foreign->getId(), 'FOREIGN-CODE', 'percent', 10);
        $em->persist($coupon);
        $em->persist($variant);
        $em->flush();
        $client->request('GET', '/admin/shop/products/'.$own->getId().'/variants/'.$variant->getId().'/edit');
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/admin/shop/coupons/'.$coupon->getId().'/edit');
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/admin/shop/manage');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $client->getCrawler()->filterXPath('//input[@name="sku" or @name="code"]'));
        $client->request('GET', '/admin/shop/coupons');
        self::assertStringNotContainsString('FOREIGN-CODE', $client->getCrawler()->text());
        $client->getCookieJar()->clear();
        $this->loginAsRole($client, 'customer');
        foreach (['/admin/shop/coupons', '/admin/shop/orders', '/admin/shop/products/'.$own->getId().'/variants'] as $url) {
            $client->request('GET', $url);
            self::assertContains($client->getResponse()->getStatusCode(), [401, 403]);
        }
    }
}
