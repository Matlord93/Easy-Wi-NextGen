<?php

declare(strict_types=1);

namespace App\Tests\Module\Core\Application\Shop;

use App\Module\Core\Application\Billing\PaymentRecorder;
use App\Module\Core\Application\Billing\PaymentSettings;
use App\Module\Core\Application\ShopAutomationService;
use App\Module\Core\Application\ShopCheckoutService;
use App\Module\Core\Application\ShopProvisioningService;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\Invoice;
use App\Module\Core\Domain\Entity\InvoiceArchive;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\ShopCategory;
use App\Module\Core\Domain\Entity\ShopCoupon;
use App\Module\Core\Domain\Entity\ShopOrder;
use App\Module\Core\Domain\Entity\ShopProduct;
use App\Module\Core\Domain\Entity\ShopProductVariant;
use App\Module\Core\Domain\Entity\ShopRental;
use App\Module\Core\Domain\Entity\Site;
use App\Module\Core\Domain\Entity\Template;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\InstanceStatus;
use App\Module\Core\Domain\Enum\InvoiceStatus;
use App\Module\Core\Domain\Enum\PaymentStatus;
use App\Module\Core\Domain\Enum\ShopOrderStatus;
use App\Module\Core\Domain\Enum\UserType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ShopCommerceWorkflowTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private User $customer;
    private ShopProduct $product;
    private ShopProductVariant $variant;
    private ShopCheckoutService $checkout;
    private array $environment = [];

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        foreach (['DATABASE_URL' => 'sqlite:///:memory:', 'EASYWI_DB_CONFIG_PATH' => '/tmp/easywi-shop-test-no-config.json'] as $key => $value) {
            $this->environment[$key] = [$_SERVER[$key] ?? null, $_ENV[$key] ?? null];
            $_SERVER[$key] = $_ENV[$key] = $value;
        }
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->customer = new User('shop@example.test', UserType::Customer);
        $this->customer->setPasswordHash('unused');
        $site = new Site('Shop', 'localhost');
        $node = new Agent('shop-node', ['key_id' => 'test', 'nonce' => 'test', 'ciphertext' => 'test']);
        $node->recordHeartbeat(['os' => 'linux'], '1.0', '127.0.0.1');
        $template = new Template('shop-fixture', 'Fixture', '', null, null, [], './server', [], [], [], [], 'true', 'true', [], [], [], [], ['linux']);
        foreach ([$this->customer, $site, $node, $template] as $entity) {
            $this->em->persist($entity);
        } $this->em->flush();
        $category = new ShopCategory($site->getId(), 'Games', 'games');
        $this->em->persist($category);
        $this->product = new ShopProduct($site->getId(), $category, 'Game server', 'Production package', 1000, $template, $node, 1, 2, 20);
        $this->product->setSubscriptionEnabled(true);
        $this->em->persist($this->product);
        $this->variant = new ShopProductVariant($this->product, 'GAME-LARGE', 'Large', 2500, 3, 8, 80);
        $this->em->persist($this->variant);
        $this->em->flush();
        $this->checkout = self::getContainer()->get(ShopCheckoutService::class);
    }

    protected function tearDown(): void
    {
        self::ensureKernelShutdown();
        foreach ($this->environment as $key => [$server, $env]) {
            if (null === $server) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $server;
            }
            if (null === $env) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $env;
            }
        }
        parent::tearDown();
    }

    private function order(string $code = '', bool $subscription = false, ?string $nonce = null): ShopOrder
    {
        $quote = $this->checkout->quote($this->product, 2, $this->variant->getId(), $code, $subscription);

        return $this->checkout->create($this->customer, $this->product, 2, $this->variant->getId(), $code, $subscription, $nonce ?? bin2hex(random_bytes(16)), ShopCheckoutService::digest($quote), Request::create('https://localhost/marketplace'));
    }

    private function pay(ShopOrder $order): void
    {
        self::getContainer()->get(PaymentRecorder::class)->record($order->getInvoice(), 'manual', 'paid-'.$order->getId(), $order->getTotalPriceCents(), 'EUR', PaymentStatus::Succeeded);
    }

    public function testUnpaidOrderNeverCreatesAnInstanceAndRetryCreatesOnlyOneInvoice(): void
    {
        $nonce = bin2hex(random_bytes(16));
        $order = $this->order(nonce: $nonce);
        self::getContainer()->get(ShopProvisioningService::class)->fulfill($order);
        self::assertSame(ShopOrderStatus::Pending, $order->getStatus());
        self::assertNull($order->getInstance());
        self::assertSame(0, $this->em->getRepository(Instance::class)->count([]));
        self::assertSame($order, $this->order(nonce: $nonce));
        self::assertSame(1, $this->em->getRepository(Invoice::class)->count([]));
        self::assertSame(1, $this->em->getRepository(InvoiceArchive::class)->count([]));
    }

    public function testPaidJavaOrderKeepsNativeMemoryReserveInItsInstallJob(): void
    {
        $this->product->getGameTemplate()->setInstallResolver(['type' => 'minecraft_vanilla']);
        $this->product->getNode()->recordHeartbeat(['os' => 'linux'], 'new-agent', '127.0.0.1', [], ['os' => 'linux', 'minecraft_managed_install' => true, 'instance_runtime_limits' => true]);
        $entry = new \App\Module\Core\Domain\Entity\MinecraftVersionCatalog('vanilla', '1.21.11', null, 'https://example.test/server.jar');
        $entry->setJavaVersion('21');
        $entry->setMetadata(['release_type' => 'release']);
        $this->em->persist($entry);
        $this->em->flush();
        $order = $this->order();
        $this->pay($order);
        self::getContainer()->get(ShopProvisioningService::class)->fulfill($order);
        $job = $this->em->getRepository(Job::class)->findOneBy(['type' => 'sniper.install']);
        self::assertNotNull($job, $order->getStatus()->value);
        self::assertSame('10240', $job->getPayload()['ram_limit']);
        self::assertSame(8192, $order->getInstance()->getRamLimit());
        self::assertSame('8192M', array_column($job->getPayload()['env_vars'], 'value', 'key')['JAVA_XMX']);
    }

    public function testPaidOrderUsesPurchasedResourcesAndQueuesExactlyOneNativeInstall(): void
    {
        $order = $this->order();
        $this->pay($order);
        $this->variant->update('Changed', 9999, 9, 9, 99, true);
        $this->em->flush();
        $service = self::getContainer()->get(ShopProvisioningService::class);
        $service->fulfill($order);
        $service->fulfill($order);
        self::assertSame(ShopOrderStatus::Provisioned, $order->getStatus());
        self::assertSame(300, $order->getInstance()->getCpuLimit());
        self::assertSame(8192, $order->getInstance()->getRamLimit());
        self::assertSame(81920, $order->getInstance()->getDiskLimit());
        self::assertSame(80 * 1024 * 1024 * 1024, $order->getInstance()->getDiskLimitBytes());
        self::assertSame(InstanceStatus::Provisioning, $order->getInstance()->getStatus());
        self::assertSame(1, $this->em->getRepository(ShopRental::class)->count([]));
        self::assertSame(1, $this->em->getRepository(Job::class)->count(['type' => 'sniper.install']));
    }

    public function testCouponIsReservedAndReleasedWhenInvoiceIsCancelled(): void
    {
        $coupon = new ShopCoupon($this->product->getSiteId(), 'SAVE20', 'percent', 20, 0, 1);
        $this->em->persist($coupon);
        $this->em->flush();
        $order = $this->order('SAVE20');
        self::assertSame(4000, $order->getTotalPriceCents());
        self::assertSame(1, $coupon->getUsedCount());
        $order->getInvoice()->cancel('Customer cancelled.');
        $this->em->flush();
        self::getContainer()->get(ShopAutomationService::class)->run();
        self::assertSame(0, $coupon->getUsedCount());
        self::assertSame(ShopOrderStatus::Cancelled, $order->getStatus());
        self::assertNull($order->getInstance());
    }

    public function testFreeCouponOrderDoesNotCreateAZeroValueInvoice(): void
    {
        $coupon = new ShopCoupon($this->product->getSiteId(), 'FREE100', 'percent', 100);
        $this->em->persist($coupon);
        $this->em->flush();
        $order = $this->order('FREE100');
        self::assertNull($order->getInvoice());
        self::getContainer()->get(ShopProvisioningService::class)->fulfill($order);
        self::assertSame(ShopOrderStatus::Provisioned, $order->getStatus());
        self::assertNotNull($order->getInstance());
        $this->variant->update('Free', 0, 1, 1, 1, true);
        $this->em->flush();
        $free = $this->order(subscription: true);
        self::getContainer()->get(ShopProvisioningService::class)->fulfill($free);
        $rental = $free->getRental();
        $until = $rental->getExpiresAt()->getTimestamp();
        $renewal = $this->checkout->extend($rental, 2, bin2hex(random_bytes(16)), Request::create('/marketplace'), true);
        $this->checkout->cancelSubscription($rental);
        self::getContainer()->get(ShopProvisioningService::class)->fulfill($renewal);
        self::assertSame(ShopOrderStatus::Cancelled, $renewal->getStatus());
        self::assertSame($until, $rental->getExpiresAt()->getTimestamp());
    }

    public function testSubscriptionRenewsOnlyAfterPaymentAndCancellationPreservesPaidTime(): void
    {
        $order = $this->order(subscription: true);
        $this->pay($order);
        self::getContainer()->get(ShopProvisioningService::class)->fulfill($order);
        $rental = $order->getRental();
        $until = $rental->getExpiresAt();
        $nonce = bin2hex(random_bytes(16));
        $renewal = $this->checkout->extend($rental, 2, $nonce, Request::create('https://localhost/marketplace'), true);
        self::assertSame($until->getTimestamp(), $rental->getExpiresAt()->getTimestamp());
        self::assertSame($renewal, $this->checkout->extend($rental, 2, $nonce, Request::create('https://localhost/marketplace'), true));
        $this->pay($renewal);
        self::getContainer()->get(ShopProvisioningService::class)->fulfill($renewal);
        self::assertGreaterThan($until, $rental->getExpiresAt());
        $paidUntil = $rental->getExpiresAt();
        $this->checkout->cancelSubscription($rental);
        self::assertFalse($rental->isAutoRenew());
        self::assertSame($paidUntil->getTimestamp(), $rental->getExpiresAt()->getTimestamp());
    }

    public function testExpiredNewRentalIsSuspendedWithoutDeletingDataOrDuplicatingStopJobs(): void
    {
        $order = $this->order();
        $this->pay($order);
        self::getContainer()->get(ShopProvisioningService::class)->fulfill($order);
        $rental = $order->getRental();
        (new \ReflectionProperty($rental, 'expiresAt'))->setValue($rental, new \DateTimeImmutable('-1 day'));
        $this->em->flush();
        $service = self::getContainer()->get(ShopAutomationService::class);
        $service->run();
        $service->run();
        self::assertSame(InstanceStatus::Suspended, $rental->getInstance()->getStatus());
        self::assertSame(1, $this->em->getRepository(Job::class)->count(['type' => 'instance.stop']));
        self::assertSame(1, $this->em->getRepository(Instance::class)->count([]));
    }

    public function testVariantFromAnotherProductIsRejected(): void
    {
        $other = clone $this->product;
        (new \ReflectionProperty($other, 'id'))->setValue($other, null);
        $this->em->persist($other);
        $this->em->flush();
        $this->expectException(\InvalidArgumentException::class);
        $this->checkout->quote($other, 1, $this->variant->getId(), '', false);
    }

    public function testChangedPriceRequiresCheckoutReview(): void
    {
        $quote = $this->checkout->quote($this->product, 1, 0, '', false);
        $this->product->setPriceMonthlyCents(2000);
        $this->em->flush();
        $this->expectException(\InvalidArgumentException::class);
        $this->checkout->create($this->customer, $this->product, 1, 0, '', false, bin2hex(random_bytes(16)), ShopCheckoutService::digest($quote), Request::create('/marketplace'));
    }

    public function testAbandonedCheckoutReleasesCouponAfterFourteenDays(): void
    {
        $coupon = new ShopCoupon($this->product->getSiteId(), 'EXPIRED20', 'percent', 20, 0, 1);
        $this->em->persist($coupon);
        $this->em->flush();
        $order = $this->order('EXPIRED20');
        (new \ReflectionProperty($order, 'createdAt'))->setValue($order, new \DateTimeImmutable('-15 days'));
        $this->em->flush();
        self::getContainer()->get(ShopAutomationService::class)->run();
        self::assertSame(ShopOrderStatus::Cancelled, $order->getStatus());
        self::assertSame(0, $coupon->getUsedCount());
        self::assertNull($order->getInstance());
    }

    public function testRecentAsynchronousCheckoutIsNotExpiredPrematurely(): void
    {
        $order = $this->order();
        (new \ReflectionProperty($order, 'createdAt'))->setValue($order, new \DateTimeImmutable('-15 days'));
        $this->em->persist(new \App\Module\Core\Domain\Entity\PaymentCheckout($order->getInvoice(), 'stripe', 'cs_async', $order->getTotalPriceCents(), 'EUR'));
        $this->em->flush();
        self::getContainer()->get(ShopAutomationService::class)->run();
        self::assertSame(ShopOrderStatus::Pending, $order->getStatus());
        self::assertSame(InvoiceStatus::Open, $order->getInvoice()->getStatus());
    }

    public function testCalendarMonthsDoNotSkipFebruaryAtMonthEnd(): void
    {
        $date = \App\Module\Core\Domain\ValueObject\BillingPeriod::addMonths(new \DateTimeImmutable('2027-01-31T12:34:56Z'), 1);
        self::assertSame('2027-02-28T12:34:56+00:00', $date->format(DATE_ATOM));
    }

    public function testInstallPreconditionsRemainRetryableWithoutCreatingAnotherInstance(): void
    {
        $order = $this->order();
        $this->pay($order);
        $template = $this->product->getTemplate();
        $template->setSupportedOs(['windows']);
        $this->em->flush();
        $service = self::getContainer()->get(ShopProvisioningService::class);
        $service->fulfill($order);
        self::assertSame(ShopOrderStatus::Pending, $order->getStatus());
        self::assertSame('TEMPLATE_OS_MISMATCH', $order->getProcessingError());
        self::assertNotEmpty($order->getInstance()->getInstallPath());
        $template->setSupportedOs(['linux']);
        $this->em->flush();
        $service->fulfill($order);
        self::assertSame(ShopOrderStatus::Provisioned, $order->getStatus());
        self::assertSame(1, $this->em->getRepository(Instance::class)->count([]));
        self::assertSame(1, $this->em->getRepository(Job::class)->count(['type' => 'sniper.install']));
    }

    public function testTwoRentalFormsOnTheSamePageHaveIndependentIdempotencyKeys(): void
    {
        $first = $this->order();
        $second = $this->order();
        $this->pay($first);
        $this->pay($second);
        $service = self::getContainer()->get(ShopProvisioningService::class);
        $service->fulfill($first);
        $service->fulfill($second);
        $nonce = bin2hex(random_bytes(16));
        $one = $this->checkout->extend($first->getRental(), 1, $nonce, Request::create('/marketplace'));
        $two = $this->checkout->extend($second->getRental(), 1, $nonce, Request::create('/marketplace'));
        self::assertNotSame($one->getId(), $two->getId());
        self::assertSame($second->getRental()->getId(), $two->getRental()->getId());
    }

    public function testInactiveCategoryCannotBePurchased(): void
    {
        $this->product->getCategory()->configure('Hidden', false);
        $this->em->flush();
        $this->expectException(\InvalidArgumentException::class);
        $this->checkout->quote($this->product, 1, 0, '', false);
    }

    public function testPaymentSettingsAreEncryptedAndBlankSecretsRetainTheirValue(): void
    {
        $settings = self::getContainer()->get(PaymentSettings::class);
        $values = ['base_url' => 'https://panel.example.test', 'stripe' => '1', 'card' => '1', 'klarna' => '1', 'sepa_debit' => '1', 'stripe_key' => 'sk_test_secret', 'stripe_webhook' => 'whsec_secret', 'currency' => 'EUR'];
        $settings->save($values);
        self::assertSame(['card', 'klarna', 'sepa_debit'], $settings->methods());
        self::assertStringNotContainsString('sk_test_secret', json_encode($settings->publicSettings()));
        self::assertStringNotContainsString('sk_test_secret', $this->em->getConnection()->fetchOne("SELECT value FROM app_settings WHERE setting_key='shop_payment_options'"));
        $values['stripe_key'] = $values['stripe_webhook'] = '';
        $settings->save($values);
        self::assertSame('sk_test_secret', $settings->secret('stripe_key'));
        self::assertTrue($settings->publicSettings()['stripe_key_configured']);
    }
}
