<?php

declare(strict_types=1);

namespace App\Tests\Module\Core\Application\Billing;

use App\Module\Core\Application\Billing\PaymentRecorder;
use App\Module\Core\Application\Billing\PaymentSettings;
use App\Module\Core\Application\Billing\PaypalPaymentProvider;
use App\Module\Core\Application\Billing\StripePaymentProvider;
use App\Module\Core\Domain\Entity\Invoice;
use App\Module\Core\Domain\Entity\Payment;
use App\Module\Core\Domain\Entity\PaymentCheckout;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\InvoiceStatus;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\InvoiceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;

final class PaymentGatewayIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Invoice $invoice;
    private PaymentSettings $settings;
    private array $environment = [];

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        foreach (['DATABASE_URL' => 'sqlite:///:memory:', 'EASYWI_DB_CONFIG_PATH' => '/tmp/easywi-gateway-no-config.json'] as $key => $value) {
            $this->environment[$key] = [$_SERVER[$key] ?? null, $_ENV[$key] ?? null];
            $_SERVER[$key] = $_ENV[$key] = $value;
        }
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $customer = new User('gateway@example.test', UserType::Customer);
        $customer->setPasswordHash('unused');
        $this->em->persist($customer);
        $this->invoice = new Invoice($customer, 'SHOP-GATEWAY-TEST', 1000, 'EUR', new \DateTimeImmutable('+7 days'));
        $this->em->persist($this->invoice);
        $this->em->flush();
        $this->settings = self::getContainer()->get(PaymentSettings::class);
        $this->settings->save(['base_url' => 'https://panel.example.test', 'stripe' => '1', 'card' => '1', 'klarna' => '1', 'sepa_debit' => '1', 'stripe_key' => 'sk_fixture', 'stripe_webhook' => 'whsec_fixture', 'paypal' => '1', 'paypal_sandbox' => '1', 'paypal_client' => 'client_fixture', 'paypal_secret' => 'secret_fixture', 'paypal_webhook' => 'webhook_fixture']);
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

    public static function methods(): iterable
    {
        yield ['card'];
        yield ['klarna'];
        yield ['sepa_debit'];
    }

    #[DataProvider('methods')]
    public function testStripeReceivesTheSelectedMethodAndBindsTheServerAmount(string $method): void
    {
        $http = new MockHttpClient(function (string $verb, string $url, array $options) use ($method): MockResponse {
            self::assertSame('POST', $verb);
            self::assertSame('https://api.stripe.com/v1/checkout/sessions', $url);
            parse_str($options['body'], $body);
            self::assertSame([$method], $body['payment_method_types']);
            self::assertSame('1000', $body['line_items'][0]['price_data']['unit_amount']);
            self::assertSame('https://panel.example.test/customer/billing', $body['success_url']);

            return new MockResponse(json_encode(['id' => 'cs_test_fixture', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_fixture']));
        });
        $instruction = $this->stripe($http)->createPaymentIntent($this->invoice, 1000, $method);
        self::assertSame('cs_test_fixture', $instruction->reference);
        $checkout = $this->em->getRepository(PaymentCheckout::class)->findOneBy(['reference' => $instruction->reference]);
        self::assertSame($this->invoice, $checkout->getInvoice());
        self::assertSame(1000, $checkout->getAmountCents());
        self::assertSame(InvoiceStatus::Open, $this->invoice->getStatus());
    }

    public function testStripeCannotRedirectToAnUnexpectedHost(): void
    {
        $http = new MockHttpClient(new MockResponse(json_encode(['id' => 'cs_test_fixture', 'url' => 'https://attacker.example.test/'])));
        $this->expectException(\RuntimeException::class);
        $this->stripe($http)->createPaymentIntent($this->invoice, 1000, 'klarna');
    }

    public function testStripeDisabledMethodCannotBeSelected(): void
    {
        $this->settings->save(['base_url' => 'https://panel.example.test', 'stripe' => '1', 'card' => '1']);
        $this->expectException(\InvalidArgumentException::class);
        $this->stripe(new MockHttpClient())->createPaymentIntent($this->invoice, 1000, 'klarna');
    }

    public function testPaypalServerCaptureVerifiesAmountCurrencyAndIsIdempotent(): void
    {
        $http = $this->paypalHttp();
        $provider = new PaypalPaymentProvider($http, $this->settings, self::getContainer()->get(PaymentRecorder::class), $this->em);
        $instruction = $provider->createPaymentIntent($this->invoice, 1000);
        self::assertSame('PAYPALORDER1234', $instruction->reference);
        self::assertSame(InvoiceStatus::Open, $this->invoice->getStatus());
        $checkout = $this->em->getRepository(PaymentCheckout::class)->findOneBy(['provider' => 'paypal']);
        $provider->capture($checkout->getId(), $instruction->reference);
        $provider->capture($checkout->getId(), $instruction->reference);
        self::assertSame(InvoiceStatus::Paid, $this->invoice->getStatus());
        self::assertSame(1, $this->em->getRepository(Payment::class)->count([]));
        self::assertSame(4, $http->getRequestsCount());
    }

    public function testPaypalReturnWithWrongTokenDoesNotCaptureOrSettle(): void
    {
        $http = $this->paypalHttp();
        $provider = new PaypalPaymentProvider($http, $this->settings, self::getContainer()->get(PaymentRecorder::class), $this->em);
        $provider->createPaymentIntent($this->invoice, 1000);
        $checkout = $this->em->getRepository(PaymentCheckout::class)->findOneBy(['provider' => 'paypal']);
        try {
            $provider->capture($checkout->getId(), 'FORGEDTOKEN123');
            self::fail('A mismatched PayPal order was accepted.');
        } catch (\DomainException) {
            self::assertSame(2, $http->getRequestsCount());
            self::assertSame(InvoiceStatus::Open, $this->invoice->getStatus());
        }
    }

    public function testPaypalUnverifiedWebhookDoesNotSettleInvoice(): void
    {
        $http = new MockHttpClient([new MockResponse('{"access_token":"fixture"}'), new MockResponse('{"verification_status":"FAILURE"}')]);
        $provider = new PaypalPaymentProvider($http, $this->settings, self::getContainer()->get(PaymentRecorder::class), $this->em);
        $request = Request::create('/billing/webhook/paypal', 'POST', content: '{"event_type":"PAYMENT.CAPTURE.COMPLETED","resource":{"id":"FAKE"}}');
        foreach (['PAYPAL-AUTH-ALGO', 'PAYPAL-CERT-URL', 'PAYPAL-TRANSMISSION-ID', 'PAYPAL-TRANSMISSION-SIG', 'PAYPAL-TRANSMISSION-TIME'] as $header) {
            $request->headers->set($header, 'fixture');
        }
        $response = $provider->webhookHandle($request);
        self::assertSame(401, $response->getStatusCode());
        self::assertSame(InvoiceStatus::Open, $this->invoice->getStatus());
        self::assertSame(0, $this->em->getRepository(Payment::class)->count([]));
    }

    public function testStripeWalletFundingRequiresSignedConfirmationAndDoesNotCreateUsageRevenue(): void
    {
        $funds = self::getContainer()->get(\App\Module\Core\Application\Contracts\WalletFunds::class);
        $nonce = bin2hex(random_bytes(16));
        $topup = $funds->request($this->invoice->getCustomer(), 1000, $nonce);
        self::assertSame($topup, $funds->request($this->invoice->getCustomer(), 1000, $nonce));
        $provider = $this->stripe(new MockHttpClient(new MockResponse('{"id":"cs_wallet_test","url":"https://checkout.stripe.com/c/pay/cs_wallet_test"}')));
        $provider->createWalletPaymentIntent($topup, 'card');
        self::assertSame(0, $topup->getAccount()->getBalanceCents());
        $payload = json_encode(['type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_wallet_test', 'client_reference_id' => $topup->getNumber(), 'payment_status' => 'paid', 'amount_total' => 1000, 'currency' => 'eur']]], JSON_THROW_ON_ERROR);
        $request = Request::create('/billing/webhook/stripe', 'POST', content: $payload);
        self::assertSame(401, $provider->webhookHandle($request)->getStatusCode());
        self::assertSame(0, $topup->getAccount()->getBalanceCents());
        $timestamp = time();
        $request->headers->set('Stripe-Signature', 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_fixture'));
        self::assertSame(200, $provider->webhookHandle($request)->getStatusCode());
        $provider->webhookHandle($request);
        self::assertSame(1000, $topup->getAccount()->getBalanceCents());
        self::assertSame(1000, $topup->getCreditedCents());
        self::assertSame(1, $this->em->getRepository(\App\Module\Core\Domain\Entity\WalletEntry::class)->count([]));
        self::assertSame(1, $this->em->getRepository(Invoice::class)->count([]));
        self::assertSame(0, $this->em->getRepository(Payment::class)->count([]));
    }

    public function testPaypalWalletCaptureCreditsOnlyOnceAndKeepsInvoicesSeparate(): void
    {
        $funds = self::getContainer()->get(\App\Module\Core\Application\Contracts\WalletFunds::class);
        $topup = $funds->request($this->invoice->getCustomer(), 1000, bin2hex(random_bytes(16)));
        $provider = new PaypalPaymentProvider($this->paypalHttp(), $this->settings, self::getContainer()->get(PaymentRecorder::class), $this->em, $funds);
        $instruction = $provider->createWalletPaymentIntent($topup, 'paypal');
        $checkout = $this->em->getRepository(PaymentCheckout::class)->findOneBy(['topup' => $topup]);
        self::assertSame(0, $topup->getAccount()->getBalanceCents());
        $provider->capture($checkout->getId(), $instruction->reference);
        $provider->capture($checkout->getId(), $instruction->reference);
        self::assertSame(1000, $topup->getAccount()->getBalanceCents());
        self::assertNotNull($topup->getPaidAt());
        self::assertSame(1, $this->em->getRepository(Invoice::class)->count([]));
        self::assertSame(0, $this->em->getRepository(Payment::class)->count([]));
        self::assertSame(1, $this->em->getRepository(\App\Module\Core\Domain\Entity\WalletEntry::class)->count([]));
    }

    public function testOnePaymentReferenceCannotBeUsedToCompleteAnotherTopupOfTheSameAmount(): void
    {
        $funds = self::getContainer()->get(\App\Module\Core\Application\Contracts\WalletFunds::class);
        $customer = $this->invoice->getCustomer();
        $first = $funds->request($customer, 1000, bin2hex(random_bytes(16)));
        $second = $funds->request($customer, 1000, bin2hex(random_bytes(16)));
        $firstCheckout = new PaymentCheckout($first, 'stripe', 'cs_first_receipt', 1000, 'EUR');
        $secondCheckout = new PaymentCheckout($second, 'stripe', 'cs_second_receipt', 1000, 'EUR');
        $this->em->persist($firstCheckout);
        $this->em->persist($secondCheckout);
        $this->em->flush();
        $funds->confirm($firstCheckout, 'cs_first_receipt');
        $conflict = false;
        try {
            $funds->confirm($secondCheckout, 'cs_first_receipt');
        } catch (\DomainException) {
            $conflict = true;
        }
        self::assertTrue($conflict, 'A payment already credited to another receipt must be rejected.');
        self::assertSame(1000, $first->getAccount()->getBalanceCents());
        self::assertNull($second->getPaidAt());
        self::assertFalse((bool) $this->em->getConnection()->fetchOne('SELECT completed FROM payment_checkouts WHERE id = ?', [$secondCheckout->getId()]));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM wallet_entries'));
    }

    private function stripe(MockHttpClient $http): StripePaymentProvider
    {
        return new StripePaymentProvider($http, self::getContainer()->get(PaymentRecorder::class), self::getContainer()->get(InvoiceRepository::class), $this->em, new NullLogger(), '', '', '/customer/billing', '/customer/billing', settings: $this->settings, walletFunds: self::getContainer()->get(\App\Module\Core\Application\Contracts\WalletFunds::class));
    }

    private function paypalHttp(): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertStringStartsWith('https://api-m.sandbox.paypal.com/', $url);
            if (str_ends_with($url, '/v1/oauth2/token')) {
                return new MockResponse('{"access_token":"fixture"}');
            }
            if (str_ends_with($url, '/capture')) {
                $checkout = $this->em->getRepository(PaymentCheckout::class)->findOneBy(['provider' => 'paypal']);

                return new MockResponse(json_encode(['id' => 'PAYPALORDER1234', 'status' => 'COMPLETED', 'purchase_units' => [['custom_id' => $checkout->getId(), 'payments' => ['captures' => [['id' => 'CAPTURE1234', 'status' => 'COMPLETED', 'amount' => ['value' => '10.00', 'currency_code' => 'EUR']]]]]]]));
            }
            self::assertSame('POST', $method);
            $body = json_decode($options['body'], true);
            self::assertSame('10.00', $body['purchase_units'][0]['amount']['value']);
            self::assertSame('EUR', $body['purchase_units'][0]['amount']['currency_code']);

            return new MockResponse('{"id":"PAYPALORDER1234","links":[{"rel":"payer-action","href":"https://www.sandbox.paypal.com/checkoutnow?token=PAYPALORDER1234"}]}');
        });
    }
}
