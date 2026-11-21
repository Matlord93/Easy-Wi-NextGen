<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Message\SendTemplateMailMessage;
use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Domain\Entity\CustomerProfile;
use App\Module\Core\Domain\Entity\InvoicePreferences;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\ModuleSetting;
use App\Module\Core\Domain\Entity\ShopCategory;
use App\Module\Core\Domain\Entity\ShopOrder;
use App\Module\Core\Domain\Entity\ShopProduct;
use App\Module\Core\Domain\Entity\Site;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class ShopCmsHttpTest extends AbstractWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSite();
        self::bootKernel();
        $this->resetShop();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new ModuleSetting('shop', '1.0.0', true));
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

    public function testShopEditorsRetainTargetsAndRejectUnsignedChanges(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'admin');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $instance = $this->seedInstance();
        $site = $em->getRepository(Site::class)->findOneBy(['host' => 'localhost']);
        $category = new ShopCategory($site->getId(), 'Workspace category', 'workspace-category');
        $category->configure('Hidden catalogue category', false);
        $product = new ShopProduct($site->getId(), $category, 'Workspace product', 'Editor fixture', 500, $instance->getTemplate(), $instance->getNode(), 1, 2, 20);
        $em->persist($category);
        $em->persist($product);
        $em->flush();
        foreach (['products' => $product->getId(), 'categories' => $category->getId()] as $kind => $id) {
            $index = $client->request('GET', '/admin/shop/'.$kind);
            self::assertResponseIsSuccessful();
            if ('products' === $kind) {
                self::assertCount(count(\App\Module\Core\Domain\Enum\ShopProductKind::cases()), $index->filterXPath('//*[@data-shop-kind]'));
                self::assertCount(0, $index->filterXPath('//form'));
            } else {
                self::assertCount(1, $index->filterXPath('//*[@id="shop-'.$kind.'-table"]'));
                self::assertCount(1, $index->filterXPath('//*[@id="shop-'.$kind.'-form"]'));
            }
            for ($refresh = 0; $refresh < 2; ++$refresh) {
                $table = $client->request('GET', '/admin/shop/'.$kind.'/table');
                self::assertResponseIsSuccessful();
                self::assertCount(1, $table->filterXPath('//*[@id="shop-'.$kind.'-table"]'));
                $editor = $client->request('GET', '/admin/shop/'.$kind.'/'.$id.'/edit');
                if ('products' === $kind) {
                    self::assertResponseRedirects('/admin/shop/rental-products/gameserver/'.$id.'/edit', 303);
                    $editor = $client->followRedirect();
                    self::assertCount(1, $editor->filterXPath('//form[@data-shop-editor="gameserver"]'));
                } else {
                    self::assertResponseIsSuccessful();
                    self::assertCount(1, $editor->filterXPath('//*[@id="shop-'.$kind.'-form"]//form'));
                }
            }
            $client->request('POST', '/admin/shop/'.$kind.'/'.$id, ['_token' => 'invalid']);
            self::assertResponseStatusCodeSame(403);
        }
    }

    public function testCmsBlockWorkspaceAndFragmentsKeepTargetsAndBlockData(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'admin');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $site = $em->getRepository(Site::class)->findOneBy(['host' => 'localhost']);
        $page = new \App\Module\Core\Domain\Entity\CmsPage($site, 'Workspace blocks', 'workspace-blocks', false);
        $block = new \App\Module\Core\Domain\Entity\CmsBlock($page, 'hero', '', 1);
        $block->setVersion(2);
        $block->setPayloadJson(['headline' => 'Workspace headline', 'subheadline' => 'Kept content', 'backgroundImagePath' => '', 'ctaText' => 'Continue', 'ctaUrl' => '/shop']);
        $em->persist($page);
        $em->persist($block);
        $em->flush();
        $index = $client->request('GET', '/admin/cms/pages');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $index->filterXPath('//*[@id="cms-pages-table"]'));
        self::assertCount(1, $index->filterXPath('//*[@id="cms-page-form"]'));
        $view = $client->request('GET', '/admin/cms/pages/'.$page->getId());
        self::assertResponseIsSuccessful();
        self::assertCount(1, $view->filterXPath('//*[@id="cms-blocks-table"]'));
        self::assertCount(1, $view->filterXPath('//*[@id="cms-block-form"]'));
        for ($refresh = 0; $refresh < 2; ++$refresh) {
            $table = $client->request('GET', '/admin/cms/pages/'.$page->getId().'/blocks/table');
            self::assertResponseIsSuccessful();
            self::assertCount(1, $table->filterXPath('//*[@id="cms-blocks-table"]'));
            self::assertSame('Workspace headline · Kept content', trim($table->filterXPath('//tbody/tr/td')->eq(2)->text()));
            $editor = $client->request('GET', '/admin/cms/pages/'.$page->getId().'/blocks/'.$block->getId().'/edit');
            self::assertResponseIsSuccessful();
            self::assertCount(1, $editor->filterXPath('//*[@id="cms-block-form"]//form'));
            self::assertSame('Workspace headline', $editor->filterXPath('//input[@name="hero_headline"]')->attr('value'));
            self::assertSame('2', $editor->filterXPath('//select[@name="version"]/option[@selected]')->attr('value'));
        }
    }

    public function testCmsBlockWorkspaceRejectsOtherSitesAndCustomers(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'admin');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $site = $em->getRepository(Site::class)->findOneBy(['host' => 'localhost']);
        $foreignSite = new Site('Foreign workspace', 'foreign-workspace.example.test');
        $own = new \App\Module\Core\Domain\Entity\CmsPage($site, 'Own workspace', 'own-workspace', false);
        $foreign = new \App\Module\Core\Domain\Entity\CmsPage($foreignSite, 'Private foreign page', 'foreign-workspace', false);
        $block = new \App\Module\Core\Domain\Entity\CmsBlock($foreign, 'text', 'PRIVATE-FOREIGN-CONTENT', 1);
        foreach ([$foreignSite, $own, $foreign, $block] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        foreach (['', '/blocks/table', '/blocks/form', '/blocks/'.$block->getId().'/edit'] as $suffix) {
            $client->request('GET', '/admin/cms/pages/'.$foreign->getId().$suffix);
            self::assertResponseStatusCodeSame(404);
            self::assertStringNotContainsString('PRIVATE-FOREIGN-CONTENT', $client->getResponse()->getContent());
        }
        $client->request('GET', '/admin/cms/pages/'.$own->getId().'/blocks/'.$block->getId().'/edit');
        self::assertResponseStatusCodeSame(404);
        $client->getCookieJar()->clear();
        $this->loginAsRole($client, 'customer');
        foreach (['', '/blocks/table', '/blocks/form', '/blocks/'.$block->getId().'/edit'] as $suffix) {
            $client->request('GET', '/admin/cms/pages/'.$own->getId().$suffix);
            self::assertContains($client->getResponse()->getStatusCode(), [401, 403]);
            self::assertStringNotContainsString('PRIVATE-FOREIGN-CONTENT', $client->getResponse()->getContent());
        }
    }

    private function resetShop(): void
    {
        $db = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $db->executeStatement("DELETE FROM cms_blocks WHERE type='shop_products'");
        foreach (['registrar_operations', 'registrar_contacts', 'rental_capacity_leases', 'wallet_entries', 'rental_notices', 'shop_domain_terms', 'shop_contracts', 'payment_checkouts', 'wallet_topups', 'rental_credits', 'rental_accounts', 'shop_orders', 'payment_checkouts', 'shop_rentals', 'shop_product_variants', 'shop_products', 'shop_categories', 'shop_coupons', 'rental_capacity_pools'] as $table) {
            $db->executeStatement('DELETE FROM '.$table);
        }
        $db->executeStatement("DELETE FROM module_settings WHERE module_key IN ('cms', 'billing', 'shop')");
        $db->executeStatement("DELETE FROM app_settings WHERE setting_key='shop_payment_options'");
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    public function testCmsCanBeDisabledWithoutDisablingPanelOrPaymentCallbacks(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'admin');
        $client->request('POST', '/admin/cms/settings/mode', ['enabled' => '0']);
        self::assertResponseStatusCodeSame(403);
        $crawler = $client->request('GET', '/admin/cms/settings');
        self::assertResponseIsSuccessful();
        $token = $crawler->filterXPath('//form[@action="/admin/cms/settings/mode"]//input[@name="_token"]')->attr('value');
        $client->request('POST', '/admin/cms/settings/mode', ['_token' => $token]);
        self::assertResponseRedirects('/admin/cms/settings?saved=1', 303);
        $client->request('GET', '/blog');
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/shop');
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/admin/cms/settings');
        self::assertResponseIsSuccessful();
        $client->request('GET', '/');
        self::assertResponseRedirects('/admin');
        $client->request('POST', '/billing/webhook/stripe', content: '{}');
        self::assertResponseStatusCodeSame(401);
        $client->request('GET', '/billing/paypal/return/'.str_repeat('a', 48).'?token=FORGED');
        self::assertResponseRedirects('/customer/billing?payment=unconfirmed', 303);
        $client->request('GET', '/login');
        self::assertNotSame(404, $client->getResponse()->getStatusCode());
    }

    public function testShopAdminSettingsAndManagementRenderAndWritesRequireCsrf(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'admin');
        $client->request('GET', '/admin/shop/settings');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Klarna', $client->getResponse()->getContent());
        $client->request('GET', '/admin/shop/manage');
        self::assertResponseIsSuccessful();
        foreach (['/admin/shop/contracts', '/admin/shop/rental-products', '/admin/shop/operations', '/admin/shop/registrar'] as $path) {
            $client->request('GET', $path);
            self::assertResponseIsSuccessful();
        }
        foreach (['/admin/shop/contracts', '/admin/shop/rental-products', '/admin/shop/operations', '/admin/shop/registrar', '/admin/shop/settings', '/admin/shop/manage', '/admin/shop/products', '/admin/shop/categories'] as $path) {
            $client->request('POST', $path);
            self::assertResponseStatusCodeSame(403);
        }
    }

    public function testPublicCatalogAndCustomerCheckoutKeepTenantAndPaymentBoundaries(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'customer');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $instance = $this->seedInstance();
        $site = $em->getRepository(Site::class)->findOneBy(['host' => 'localhost']);
        $em->persist(new \App\Module\Core\Domain\Entity\RentalCapacityPool('agent:'.$instance->getNode()->getId(), ['cpu' => 1000, 'ram_mib' => 10000000, 'disk_mib' => 10000000, 'ports' => 10000, 'ipv4' => 1000, 'slots' => 10000, 'systems' => 1000]));
        $category = new ShopCategory($site->getId(), 'Games', 'games');
        $em->persist($category);
        $product = new ShopProduct($site->getId(), $category, 'HTTP game package', 'Server package', 500, $instance->getTemplate(), $instance->getNode(), 1, 2, 20, null, true, true, true);
        $em->persist($product);
        $em->flush();
        $id = $product->getId();
        $home = $em->getRepository(\App\Module\Core\Domain\Entity\CmsPage::class)->findOneBy(['site' => $site, 'slug' => 'startseite']);
        $block = new \App\Module\Core\Domain\Entity\CmsBlock($home, 'shop_products', '', 0);
        $block->setVersion(2);
        $block->setPayloadJson(['limit' => 3]);
        $em->persist($block);
        $em->flush();
        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('HTTP game package', $client->getResponse()->getContent());

        $client->request('GET', '/shop');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('HTTP game package', $client->getResponse()->getContent());
        $client->request('GET', '/marketplace');
        self::assertResponseIsSuccessful();
        $crawler = $client->request('GET', '/marketplace/checkout?product_id='.$id);
        $preview = $crawler->filterXPath('//form[input[@name="stage" and @value="preview"]]')->form(['billing_mode' => 'wallet']);
        $crawler = $client->submit($preview);
        self::assertResponseIsSuccessful();
        self::assertSame('wallet', $crawler->filterXPath('//form[input[@name="stage" and @value="profile"]]//input[@name="billing_mode"]')->attr('value'));
        $crawler = $client->request('GET', '/shop/products/'.$id);
        self::assertResponseIsSuccessful();
        $form = $crawler->filterXPath('//form[input[@name="stage" and @value="profile"]]')->form([
            'firstname' => 'Ada', 'lastname' => 'Lovelace', 'address' => 'Musterstraße 1', 'postal' => '10115',
            'city' => 'Berlin', 'country' => 'DE',
        ]);
        $client->submit($form);
        self::assertResponseStatusCodeSame(303);
        self::assertSame(0, self::getContainer()->get(EntityManagerInterface::class)->getRepository(ShopOrder::class)->count([]));
        $crawler = $client->followRedirect();
        $form = $crawler->filterXPath('//form[input[@name="stage" and @value="order"]]')->form(['rental_terms' => '1']);
        $values = $form->getPhpValues();
        $client->submit($form);
        self::assertResponseStatusCodeSame(303);
        $location = $client->getResponse()->headers->get('Location');
        self::assertStringStartsWith('/shop/contracts/', $location);
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Mietvertrag', $client->getResponse()->getContent());
        $client->request('POST', '/shop/products/'.$id, $values);
        self::assertResponseRedirects($location, 303);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertSame(1, $em->getRepository(ShopOrder::class)->count([]));
        self::assertSame(1, $em->getRepository(\App\Module\Core\Domain\Entity\ShopContract::class)->count([]));
        self::assertSame(2, $em->getRepository(Instance::class)->count([]));
        $contract = $em->getRepository(\App\Module\Core\Domain\Entity\ShopContract::class)->findOneBy([]);
        $contractId = $contract->getId();
        $em->getRepository(ModuleSetting::class)->findOneBy(['moduleKey' => 'shop'])->setEnabled(false);
        $em->flush();
        $crawler = $client->request('GET', '/marketplace/contracts/'.$contractId);
        self::assertResponseIsSuccessful();
        $client->submit($crawler->filterXPath('//form[input[@name="_token"]]')->form());
        self::assertResponseRedirects('/marketplace/contracts/'.$contractId, 303);
        $contract = self::getContainer()->get(EntityManagerInterface::class)->find(\App\Module\Core\Domain\Entity\ShopContract::class, $contractId);
        self::assertNotNull($contract->getCancelledAt());
        self::assertSame('ended', $contract->getStatus());

        $client->request('POST', '/marketplace/order', ['product_id' => $id]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAnonymousCmsContractSelectionIsRestoredAfterLoginWithoutPlacingAnOrder(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $instance = $this->seedInstance();
        $site = $em->getRepository(Site::class)->findOneBy(['host' => 'localhost']);
        $em->persist(new \App\Module\Core\Domain\Entity\RentalCapacityPool('agent:'.$instance->getNode()->getId(), ['cpu' => 1000, 'ram_mib' => 10000000, 'disk_mib' => 10000000, 'ports' => 10000, 'ipv4' => 1000, 'slots' => 10000, 'systems' => 1000]));
        $category = new ShopCategory($site->getId(), 'Voice', 'voice');
        $em->persist($category);
        $product = new ShopProduct($site->getId(), $category, 'Public contract', '', 500, $instance->getTemplate(), $instance->getNode(), 1, 2, 20, null, true, true, true);
        $em->persist($product);
        $em->flush();
        $productId = $product->getId();
        $crawler = $client->request('GET', '/shop/products/'.$productId.'?billing_mode=wallet');
        self::assertResponseIsSuccessful();
        $form = $crawler->filterXPath('//form[input[@name="stage" and @value="account"]]')->form();
        $client->submit($form);
        self::assertResponseStatusCodeSame(303);
        $location = $client->getResponse()->headers->get('Location');
        self::assertStringStartsWith('/login?target=', $location);
        $target = rawurldecode(substr($location, strlen('/login?target=')));
        self::assertStringStartsWith('/shop/products/'.$productId.'?intent=', $target);
        self::assertSame(0, self::getContainer()->get(EntityManagerInterface::class)->getRepository(ShopOrder::class)->count([]));
        $this->loginAsRole($client, 'customer');
        $crawler = $client->request('GET', $target);
        self::assertResponseIsSuccessful();
        self::assertSame('wallet', $crawler->filterXPath('//form[input[@name="stage" and @value="profile"]]//input[@name="billing_mode"]')->attr('value'));
        self::assertSame(0, self::getContainer()->get(EntityManagerInterface::class)->getRepository(ShopOrder::class)->count([]));
    }

    public function testUnauthenticatedCheckoutRetainsItsProductAcrossLogin(): void
    {
        $client = self::createClient();
        $target = '/marketplace/checkout?product_id=42&months=3';
        $client->request('GET', $target);
        self::assertResponseRedirects('/login?target='.rawurlencode($target));
    }

    public function testShopRegistrationStoresBillingDataAndReturnsToTheSelectedPlanAfterVerification(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $transport = new InMemoryTransport();
        self::getContainer()->set('messenger.transport.async', $transport);
        self::getContainer()->get(AppSettingsService::class)->updateSettings([
            AppSettingsService::KEY_REGISTRATION_ENABLED => true,
            AppSettingsService::KEY_ANTI_ABUSE_ENABLE_POW_REGISTRATION => false,
            AppSettingsService::KEY_ANTI_ABUSE_ENABLE_CAPTCHA_REGISTRATION => false,
            AppSettingsService::KEY_ANTI_ABUSE_MIN_SUBMIT_SECONDS => 1,
        ]);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $instance = $this->seedInstance();
        $site = $em->getRepository(Site::class)->findOneBy(['host' => 'localhost']);
        $em->persist(new \App\Module\Core\Domain\Entity\RentalCapacityPool('agent:'.$instance->getNode()->getId(), ['cpu' => 1000, 'ram_mib' => 10000000, 'disk_mib' => 10000000, 'ports' => 10000, 'ipv4' => 1000, 'slots' => 10000, 'systems' => 1000]));
        $category = new ShopCategory($site->getId(), 'Games', 'registration-games');
        $product = new ShopProduct($site->getId(), $category, 'Registration product', '', 500, $instance->getTemplate(), $instance->getNode(), 1, 2, 20);
        $em->persist($category);
        $em->persist($product);
        $em->flush();

        $page = $client->request('GET', '/shop/products/'.$product->getId().'?billing_mode=wallet');
        $client->submit($page->filterXPath('//form[input[@name="stage" and @value="account"]]//button[@name="register"]')->form());
        self::assertResponseStatusCodeSame(303);
        $register = $client->followRedirect();
        self::assertResponseIsSuccessful();
        $target = $register->filterXPath('//input[@name="target"]')->attr('value');
        self::assertStringStartsWith('/shop/products/'.$product->getId().'?intent=', $target);
        self::assertCount(0, $register->filterXPath('//a[contains(@href,"account=member")]'));
        sleep(1);
        $client->submit($register->filterXPath('//form[@method="post"]')->form([
            'email' => 'shop-onboarding@example.test', 'password' => 'P@ssw0rd!', 'password_confirm' => 'P@ssw0rd!',
            'firstname' => 'Ada', 'lastname' => 'Lovelace', 'address' => 'Musterstraße 1', 'postal' => '10115',
            'city' => 'Berlin', 'country' => 'DE', 'company' => 'Analytical Engine', 'vat_id' => 'DE123456789',
            'phone' => '+49 30 123456', 'consents' => '1',
        ]));
        self::assertResponseIsSuccessful();
        $envelopes = $transport->getSent();
        self::assertCount(1, $envelopes);
        $mail = $envelopes[0]->getMessage();
        self::assertInstanceOf(SendTemplateMailMessage::class, $mail);
        $verification = $mail->getContext()['verification_url'];
        $user = $em->getRepository(\App\Module\Core\Domain\Entity\User::class)->findOneBy(['email' => 'shop-onboarding@example.test']);
        self::assertTrue($user->isEmailVerificationPending());
        self::assertFalse($user->isCustomerAccessEnabled());
        self::assertSame('Ada Lovelace', $user->getName());
        $profile = $em->getRepository(CustomerProfile::class)->findOneBy(['customer' => $user]);
        self::assertSame('Musterstraße 1', $profile->getAddress());
        self::assertSame('DE123456789', $profile->getVatId());
        self::assertSame('de_DE', $em->getRepository(InvoicePreferences::class)->findOneBy(['customer' => $user])->getLocale());
        self::assertSame(0, $em->getRepository(ShopOrder::class)->count([]));

        $client->getCookieJar()->clear();
        $client->request('GET', $verification);
        self::assertResponseRedirects('/login?target='.rawurlencode($target));
        $client->followRedirect();
        self::assertStringContainsString('Deine E-Mail-Adresse ist bestätigt', $client->getResponse()->getContent());
        $client->request('POST', '/login', ['email' => $user->getEmail(), 'password' => 'P@ssw0rd!', 'target' => $target]);
        self::assertResponseRedirects($target);
        $final = $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSame('wallet', $final->filterXPath('//form[input[@name="stage" and @value="order"]]//input[@name="billing_mode"]')->attr('value'));
        $verifiedUser = $em->getRepository(\App\Module\Core\Domain\Entity\User::class)->findOneBy(['email' => 'shop-onboarding@example.test']);
        self::assertTrue($verifiedUser->isCustomerAccessEnabled());
        self::assertSame(0, $em->getRepository(ShopOrder::class)->count([]));
    }

    public function testWalletFundingIsOwnerScopedAndBankCreditRequiresAnAdminConfirmedAmount(): void
    {
        $client = self::createClient();
        $customer = $this->loginAsRole($client, 'customer');
        $crawler = $client->request('GET', '/marketplace/wallet');
        self::assertResponseIsSuccessful();
        $client->request('POST', '/marketplace/wallet', ['amount' => '5.00']);
        self::assertResponseStatusCodeSame(403);
        self::assertResponseHeaderSame('X-EasyWi-Problem', 'form_expired');
        $crawler = $client->request('GET', '/marketplace/wallet');
        $form = $crawler->filterXPath('//form[input[@name="nonce"]]')->form(['amount' => '5.00']);
        $client->submit($form);
        self::assertResponseStatusCodeSame(303);
        $location = $client->getResponse()->headers->get('Location');
        $id = basename($location);
        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();
        $client->request('POST', $location, ['provider' => 'bank_transfer']);
        self::assertResponseStatusCodeSame(403);
        $crawler = $client->request('GET', $location);
        $client->submit($crawler->filterXPath('//form')->form(['provider' => 'bank_transfer']));
        self::assertResponseStatusCodeSame(303);
        $client->getCookieJar()->clear();
        $this->loginAsRole($client, 'admin');
        $crawler = $client->request('GET', '/admin/shop/operations');
        self::assertResponseIsSuccessful();
        $selector = '//form[input[@name="action" and @value="bank-confirm"] and input[@name="topup_id" and @value="'.$id.'"]]';
        $form = $crawler->filterXPath($selector)->form(['amount' => '1.00', 'reference' => 'bank-http-test-001']);
        $client->submit($form);
        self::assertResponseStatusCodeSame(422);
        $crawler = $client->request('GET', '/admin/shop/operations');
        $form = $crawler->filterXPath($selector)->form(['amount' => '5.00', 'reference' => 'bank-http-test-001']);
        $values = $form->getPhpValues();
        $client->submit($form);
        self::assertResponseRedirects('/admin/shop/operations?saved=1', 303);
        $client->request('POST', '/admin/shop/operations', $values);
        self::assertResponseStatusCodeSame(303);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $topup = $em->find(\App\Module\Core\Domain\Entity\WalletTopup::class, $id);
        self::assertSame(500, $topup->getAccount()->getBalanceCents());
        self::assertSame(1, $em->getRepository(\App\Module\Core\Domain\Entity\WalletEntry::class)->count([]));
        self::assertSame(0, $em->getRepository(\App\Module\Core\Domain\Entity\Invoice::class)->count([]));
        $client->getCookieJar()->clear();
        $other = $this->createUser('wallet-other@example.test', \App\Module\Core\Domain\Enum\UserType::Customer);
        $otherEmail = $other->getEmail();
        $client->request('GET', '/');
        $client->request('POST', '/login', ['email' => $otherEmail, 'password' => 'P@ssw0rd!']);
        $client->request('GET', $location);
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/marketplace/wallet');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('bank-http-test-001', $client->getResponse()->getContent());
    }

    public function testAdminCannotPromiseRetentionForAnAlreadyQueuedDeletion(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'customer');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $instance = $this->seedInstance();
        $site = $em->getRepository(Site::class)->findOneBy(['host' => 'localhost']);
        $em->persist(new \App\Module\Core\Domain\Entity\RentalCapacityPool('agent:'.$instance->getNode()->getId(), ['cpu' => 1000, 'ram_mib' => 10000000, 'disk_mib' => 10000000, 'ports' => 10000, 'ipv4' => 1000, 'slots' => 10000, 'systems' => 1000]));
        $category = new ShopCategory($site->getId(), 'Retention', 'retention');
        $em->persist($category);
        $product = new ShopProduct($site->getId(), $category, 'Retention HTTP fixture', '', 500, $instance->getTemplate(), $instance->getNode(), 1, 2, 20);
        $em->persist($product);
        $em->flush();
        $customer = $em->getRepository(\App\Module\Core\Domain\Entity\User::class)->findOneBy(['email' => 'customer+forum@example.test']);
        $quote = self::getContainer()->get(\App\Module\Core\Application\Contracts\ContractQuote::class)->build($product, ['billing_mode' => 'postpaid'], true);
        $contract = self::getContainer()->get(\App\Module\Core\Application\Contracts\RentalContracts::class)->order($customer, $product, ['billing_mode' => 'postpaid'], bin2hex(random_bytes(16)), \App\Module\Core\Application\Contracts\ContractQuote::digest($quote));
        $contract->cleanupJobs([['system' => 'core', 'id' => 'already-queued-delete']]);
        $em->flush();
        $id = $contract->getId();
        $client->getCookieJar()->clear();
        $this->loginAsRole($client, 'admin');
        $crawler = $client->request('GET', '/admin/shop/operations');
        self::assertResponseIsSuccessful();
        $values = $crawler->filterXPath('//form[label/input[@name="contract_id"]]')->form()->getPhpValues();
        $values['contract_id'] = (string) $id;
        $values['action'] = 'hold';
        $client->request('POST', '/admin/shop/operations', $values);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Die Bereinigung wurde bereits beauftragt.', $client->getResponse()->getContent());
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $contract = $em->find(\App\Module\Core\Domain\Entity\ShopContract::class, $id);
        self::assertFalse($contract->isRetentionHold());
        $client->request('GET', '/admin/shop/operations');
        self::assertResponseIsSuccessful();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $contract = $em->find(\App\Module\Core\Domain\Entity\ShopContract::class, $id);
        $contract->purged(new \DateTimeImmutable());
        $em->flush();
        $client->getCookieJar()->clear();
        $client->request('GET', '/');
        $client->request('POST', '/login', ['email' => 'customer+forum@example.test', 'password' => 'P@ssw0rd!']);
        $client->request('GET', '/marketplace/contracts/'.$id);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Die Systemdaten wurden am', $client->getResponse()->getContent());
        self::assertStringNotContainsString('Datenaufbewahrung bis mindestens', $client->getResponse()->getContent());
    }
}
