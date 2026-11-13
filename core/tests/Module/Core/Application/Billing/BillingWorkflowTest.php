<?php

declare(strict_types=1);

namespace App\Tests\Module\Core\Application\Billing;

use App\Module\Core\Application\Billing\AccountingExportService;
use App\Module\Core\Application\Billing\BillingAmount;
use App\Module\Core\Application\Billing\BillingSettings;
use App\Module\Core\Application\Billing\DunningWorkflow;
use App\Module\Core\Application\Billing\InvoiceAdjustmentService;
use App\Module\Core\Application\Billing\InvoiceArchiveManager;
use App\Module\Core\Application\Billing\InvoiceDocumentService;
use App\Module\Core\Application\Billing\InvoiceLayoutRenderer;
use App\Module\Core\Application\Billing\InvoiceStatusUpdater;
use App\Module\Core\Application\Billing\PaymentRecorder;
use App\Module\Core\Application\Billing\StripePaymentProvider;
use App\Module\Core\Application\Billing\DunningNotificationSenderInterface;
use App\Module\Core\Application\Scheduler\BillingScheduleHandler;
use App\Module\Core\Domain\Entity\Invoice;
use App\Module\Core\Domain\Entity\Payment;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\InvoiceStatus;
use App\Module\Core\Domain\Enum\PaymentStatus;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\InvoiceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;

final class BillingWorkflowTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private User $customer;
    private User $admin;
    private array $environment = [];

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        foreach (['DATABASE_URL' => 'sqlite:///:memory:', 'EASYWI_DB_CONFIG_PATH' => '/tmp/easywi-billing-test-no-config.json'] as $key => $value) {
            $this->environment[$key] = [$_SERVER[$key] ?? null, $_ENV[$key] ?? null];
            $_SERVER[$key] = $_ENV[$key] = $value;
        }
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->customer = new User('billing@example.test', UserType::Customer);
        $this->admin = new User('admin-billing@example.test', UserType::Admin);
        $this->customer->setPasswordHash('unused-billing-test-hash');
        $this->admin->setPasswordHash('unused-billing-test-hash');
        $this->em->persist($this->customer);
        $this->em->persist($this->admin);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        self::ensureKernelShutdown();
        foreach ($this->environment as $key => [$server, $env]) {
            if ($server === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $server;
            }
            if ($env === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $env;
            }
        }
        parent::tearDown();
    }

    public function testPartialPaymentAndDuplicateCallbackSettleExactlyOnce(): void
    {
        $invoice = $this->invoice();
        $recorder = self::getContainer()->get(PaymentRecorder::class);
        $first = $recorder->record($invoice, 'stripe', 'cs-first', 1900, 'EUR', PaymentStatus::Succeeded);
        self::assertSame(10000, $invoice->getOutstandingCents());
        self::assertNotSame(InvoiceStatus::Paid, $invoice->getStatus());
        self::assertSame($first, $recorder->record($invoice, 'stripe', 'cs-first', 1900, 'EUR', PaymentStatus::Succeeded));
        self::assertCount(1, $invoice->getPayments());
        $recorder->record($invoice, 'stripe', 'cs-second', 10000, 'EUR', PaymentStatus::Succeeded);
        self::assertSame(InvoiceStatus::Paid, $invoice->getStatus());
        self::assertSame(0, $invoice->getOutstandingCents());
        self::assertSame(2, $this->em->getRepository(Payment::class)->count([]));
    }

    public function testPendingPaymentCanSucceedWithoutCreatingAnotherPayment(): void
    {
        $invoice = $this->invoice();
        $recorder = self::getContainer()->get(PaymentRecorder::class);
        $recorder->record($invoice, 'stripe', 'cs-pending', 11900, 'EUR', PaymentStatus::Pending);
        self::assertSame(11900, $invoice->getOutstandingCents());
        $recorder->record($invoice, 'stripe', 'cs-pending', 11900, 'EUR', PaymentStatus::Succeeded);
        self::assertSame(InvoiceStatus::Paid, $invoice->getStatus());
        self::assertCount(1, $invoice->getPayments());
    }

    public function testPaymentWithWrongCurrencyIsRejectedBeforeChangingInvoice(): void
    {
        $invoice = $this->invoice();
        try {
            self::getContainer()->get(PaymentRecorder::class)->record($invoice, 'stripe', 'cs-wrong-currency', 11900, 'USD', PaymentStatus::Succeeded);
            self::fail('Wrong currency accepted.');
        } catch (\InvalidArgumentException) {
            self::assertCount(0, $invoice->getPayments());
            self::assertSame(11900, $invoice->getOutstandingCents());
        }
    }

    public function testCreditAndCancellationKeepOriginalAmountAndCannotReopen(): void
    {
        $invoice = $this->invoice();
        $adjustments = self::getContainer()->get(InvoiceAdjustmentService::class);
        $adjustments->credit($invoice, 1900, 'Service adjustment', $this->admin);
        self::assertSame(10000, $invoice->getOutstandingCents());
        $adjustments->cancel($invoice, 'Cancelled order', $this->admin);
        self::assertSame(InvoiceStatus::Cancelled, $invoice->getStatus());
        self::assertSame(11900, $invoice->getAmountTotalCents());
        self::assertSame(0, $invoice->getOutstandingCents());
        self::assertNull($invoice->getPaidAt());
        self::assertCount(2, $invoice->getCreditNotes());
        self::getContainer()->get(InvoiceStatusUpdater::class)->syncStatus($invoice);
        self::assertSame(InvoiceStatus::Cancelled, $invoice->getStatus());
    }

    public function testOverCreditDoesNotChangeTheInvoice(): void
    {
        $invoice = $this->invoice();
        $this->expectException(\InvalidArgumentException::class);
        self::getContainer()->get(InvoiceAdjustmentService::class)->credit($invoice, 12000, 'Too much', $this->admin);
    }

    public function testRemindersWaitBetweenLevelsEvenForLongOverdueInvoices(): void
    {
        $mail = $this->createMock(DunningNotificationSenderInterface::class);
        $mail->expects(self::exactly(2))->method('send')->willReturn(true);
        $invoice = $this->invoice();
        $now = new \DateTimeImmutable('2027-01-01 12:00:00');
        $workflow = new DunningWorkflow(self::getContainer()->get(\App\Repository\DunningReminderRepository::class), $this->em, self::getContainer()->get(\App\Module\Core\Application\AuditLogger::class), $mail, self::getContainer()->get(BillingSettings::class));
        self::assertSame(1, $workflow->apply($invoice, null, $now)?->getLevel());
        self::assertNull($workflow->apply($invoice, null, $now->modify('+1 hour')));
        self::assertSame(2, $workflow->apply($invoice, null, $now->modify('+7 days'))?->getLevel());
        self::assertCount(2, $invoice->getReminders());
        self::assertSame('2026-01-01', $invoice->getDueDate()->format('Y-m-d'));
    }

    public function testFailedNotificationIsRetriedWithoutDuplicateFeeOrEscalation(): void
    {
        self::getContainer()->get(BillingSettings::class)->save(['reminder_fees' => ['1.00', '2.00', '3.00']]);
        $mail = $this->createMock(DunningNotificationSenderInterface::class);
        $mail->expects(self::exactly(2))->method('send')->willReturnOnConsecutiveCalls(false, true);
        $invoice = $this->invoice();
        $workflow = new DunningWorkflow(self::getContainer()->get(\App\Repository\DunningReminderRepository::class), $this->em, self::getContainer()->get(\App\Module\Core\Application\AuditLogger::class), $mail, self::getContainer()->get(BillingSettings::class));
        $now = new \DateTimeImmutable('2027-01-01');
        $first = $workflow->apply($invoice, null, $now);
        $retry = $workflow->apply($invoice, null, $now->modify('+1 hour'));
        self::assertSame($first, $retry);
        self::assertSame(12000, $invoice->getAmountDueCents());
        self::assertCount(1, $invoice->getReminders());
        self::assertNotNull($retry?->getSentAt());
    }

    public function testInvoiceSnapshotAndArchivedPdfSurviveChangedSettings(): void
    {
        $settings = self::getContainer()->get(BillingSettings::class);
        $settings->save(['company_name' => 'Original Company']);
        $invoice = $this->invoice();
        $renderer = self::getContainer()->get(InvoiceLayoutRenderer::class);
        $request = Request::create('http://localhost/');
        $renderer->snapshot($invoice, $request, BillingAmount::splitGross(11900, 'standard', 1900), 'Hosting service');
        $this->em->flush();
        $settings->save(['company_name' => 'Changed Company']);
        $html = $renderer->render($invoice, $request, '{{ company.name }} {{ invoice.net_cents }} {{ invoice.tax_cents }}');
        self::assertSame('Original Company 10000 1900', $html);
        $documents = self::getContainer()->get(InvoiceDocumentService::class);
        $pdf = $documents->generate($invoice, $request);
        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertGreaterThan(2000, strlen($pdf));
        self::getContainer()->get(InvoiceArchiveManager::class)->archiveInvoiceContent($invoice, $pdf, 'original.pdf', 'application/pdf');
        self::assertSame($pdf, $documents->download($invoice, $request)->getContent());
        self::assertSame('application/pdf', $documents->download($invoice, $request)->headers->get('Content-Type'));
    }

    public function testCustomLayoutCannotReadServerFiles(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::getContainer()->get(InvoiceLayoutRenderer::class)->render($this->invoice(), Request::create('/'), "{{ source('/etc/passwd') }}");
    }

    public function testInvoiceValuesStayEscapedAndLiteralPhpIsNotExecuted(): void
    {
        $invoice = $this->invoice();
        $invoice->setBillingDetails(['locale' => 'de', 'customer' => ['name' => '<img src=x onerror=alert(1)>'], 'company' => ['name' => 'Company']]);
        $html = self::getContainer()->get(InvoiceLayoutRenderer::class)->render($invoice, Request::create('/'), '{{ customer.name }} <?php echo "executed"; ?>');
        self::assertStringContainsString('&lt;img', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringContainsString('<?php echo "executed"; ?>', $html);
    }

    public function testCsvProtectsSpreadsheetFormulasAndContainsTaxBreakdown(): void
    {
        $invoice = $this->invoice('=SUM(1,1)');
        $invoice->setBillingDetails(BillingAmount::splitGross(11900, 'standard', 1900));
        $csv = (new AccountingExportService())->exportInvoices([$invoice], []);
        self::assertStringContainsString("'=SUM(1,1)", $csv);
        self::assertStringContainsString('100.00;19.00;19.00;standard', $csv);
    }

    public function testSignedUnpaidStripeEventDoesNotSettleAndPaidEventIsIdempotent(): void
    {
        $invoice = $this->invoice();
        $provider = $this->stripe(new MockHttpClient());
        $session = ['id' => 'cs-event', 'client_reference_id' => $invoice->getNumber(), 'amount_total' => 11900, 'currency' => 'eur', 'payment_status' => 'unpaid'];
        self::assertSame(200, $provider->webhookHandle($this->event($session))->getStatusCode());
        self::assertCount(0, $invoice->getPayments());
        $session['payment_status'] = 'paid';
        $provider->webhookHandle($this->event($session));
        $provider->webhookHandle($this->event($session));
        self::assertSame(InvoiceStatus::Paid, $invoice->getStatus());
        self::assertCount(1, $invoice->getPayments());
        self::assertSame(401, $provider->webhookHandle($this->event($session, time() - 3600))->getStatusCode());
    }

    public function testStripeReconciliationReadsAllPages(): void
    {
        $invoice = $this->invoice();
        $http = new MockHttpClient([
            new MockResponse(json_encode(['data' => [['id' => 'cs-page-one', 'client_reference_id' => $invoice->getNumber(), 'amount_total' => 1900, 'currency' => 'eur', 'payment_status' => 'paid']], 'has_more' => true])),
            new MockResponse(json_encode(['data' => [['id' => 'cs-page-two', 'client_reference_id' => $invoice->getNumber(), 'amount_total' => 10000, 'currency' => 'eur', 'payment_status' => 'paid']], 'has_more' => false])),
        ]);
        $this->stripe($http)->reconcile();
        self::assertSame(2, $http->getRequestsCount());
        self::assertSame(InvoiceStatus::Paid, $invoice->getStatus());
    }

    public function testPendingStripePaymentCanCompleteThroughWebhook(): void
    {
        $invoice = $this->invoice();
        self::getContainer()->get(PaymentRecorder::class)->record($invoice, 'stripe', 'cs-promote', 11900, 'EUR', PaymentStatus::Pending);
        $this->stripe(new MockHttpClient())->webhookHandle($this->event(['id' => 'cs-promote', 'client_reference_id' => $invoice->getNumber(), 'amount_total' => 11900, 'currency' => 'eur', 'payment_status' => 'paid']));
        self::assertSame(InvoiceStatus::Paid, $invoice->getStatus());
        self::assertCount(1, $invoice->getPayments());
    }

    public function testDisabledAutomationDoesNotSendReminders(): void
    {
        $mail = $this->createMock(DunningNotificationSenderInterface::class);
        $mail->expects(self::never())->method('send');
        $this->invoice();
        self::assertSame('skipped', self::getContainer()->get(\App\Module\Core\Application\Scheduler\ScheduleHandlerRegistry::class)->get('billing.automation')->runDue()->status);
    }

    public function testEnabledAutomationIsWiredAndRunsAtMostOncePerHour(): void
    {
        self::getContainer()->get(BillingSettings::class)->save(['automation_enabled' => true]);
        $invoice = $this->invoice();
        $handler = self::getContainer()->get(\App\Module\Core\Application\Scheduler\ScheduleHandlerRegistry::class)->get('billing.automation');
        self::assertNotNull($handler);
        $now = new \DateTimeImmutable('2027-01-01 12:01:00');
        self::assertSame('success', $handler->runDue($now)->status);
        self::assertSame(InvoiceStatus::PastDue, $invoice->getStatus());
        self::assertSame('skipped', $handler->runDue($now->modify('+1 minute'))->status);
        self::assertSame('success', $handler->runDue($now->modify('+1 hour'))->status);
        self::assertCount(0, $invoice->getReminders());
    }

    private function invoice(string $number = 'INV-TEST'): Invoice
    {
        $invoice = new Invoice($this->customer, $number, 11900, 'EUR', new \DateTimeImmutable('2026-01-01'));
        $this->em->persist($invoice);
        $this->em->flush();

        return $invoice;
    }

    private function stripe(MockHttpClient $http): StripePaymentProvider
    {
        return new StripePaymentProvider($http, self::getContainer()->get(PaymentRecorder::class), self::getContainer()->get(InvoiceRepository::class), $this->em, new NullLogger(), 'sk_test', 'whsec_test', '/customer/billing', '/customer/billing');
    }

    private function event(array $session, ?int $timestamp = null): Request
    {
        $payload = json_encode(['type' => 'checkout.session.completed', 'data' => ['object' => $session]]);
        $timestamp ??= time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_test');

        return Request::create('/billing/webhook/stripe', 'POST', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 't='.$timestamp.',v1='.$signature], $payload);
    }
}
