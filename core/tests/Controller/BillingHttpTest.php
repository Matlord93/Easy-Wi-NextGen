<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Domain\Entity\Invoice;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class BillingHttpTest extends AbstractWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSite();
        self::bootKernel();
        self::getContainer()->get('cache.rate_limiter')->clear();
        self::ensureKernelShutdown();
    }

    public function testAdminCanIssueTaxedInvoiceAndPreviewIt(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'admin');
        $customer = $this->createUser('invoice-owner@example.test', UserType::Customer);
        $crawler = $client->request('GET', '/admin/billing/invoices/new');
        self::assertResponseIsSuccessful();
        self::assertSame(self::getContainer()->get(CsrfTokenManagerInterface::class)->getToken('billing')->getValue(), $crawler->filterXPath('//form[@action="/admin/billing/invoices"]//input[@name="_token"]')->attr('value'));
        $client->submit($crawler->filterXPath('//form[@action="/admin/billing/invoices"]')->form([
            'customer_id' => (string) $customer->getId(), 'number' => 'INV-HTTP-'.bin2hex(random_bytes(4)),
            'items[0][unit_price]' => '119,00', 'items[0][quantity]' => '1', 'currency' => 'EUR', 'due_date' => '2027-12-01',
            'tax_mode' => 'standard', 'items[0][tax_rate]' => '19', 'items[0][description]' => 'Hosting test',
        ]));
        self::assertResponseStatusCodeSame(303);
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertCount(1, $client->getCrawler()->filterXPath('//iframe[@sandbox]'));
        self::assertCount(1, $client->getCrawler()->filterXPath('//form[contains(@action, "/credit")]//input[@name="_token"]'));
    }

    public function testAdminMutationWithoutCsrfIsForbidden(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'admin');
        $client->request('POST', '/admin/billing/invoices', ['amount' => '119']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testCustomerHistoryAndPdfOnlyExposeOwnInvoices(): void
    {
        $client = self::createClient();
        $customer = $this->loginAsRole($client, 'customer');
        $own = $this->invoice($customer);
        $other = $this->createUser('other-billing@example.test', UserType::Customer);
        $foreign = $this->invoice($other);
        $ownId = $own->getId();
        $foreignId = $foreign->getId();
        $number = $own->getNumber();
        $foreignNumber = $foreign->getNumber();
        $client->request('GET', '/customer/billing');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($number, $client->getResponse()->getContent());
        self::assertStringNotContainsString($foreignNumber, $client->getResponse()->getContent());
        $client->request('GET', '/customer/billing/invoices/'.$ownId.'/pdf');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertStringStartsWith('%PDF-', $client->getResponse()->getContent());
        $crawler = $client->request('GET', '/customer/billing/invoices/'.$ownId);
        $client->submit($crawler->filterXPath('//form[contains(@action, "/pay")]')->form());
        self::assertResponseRedirects('/customer/billing/invoices/'.$ownId.'?payment=manual', 303);
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($number, $client->getResponse()->getContent());
        foreach (['', '/pdf'] as $suffix) {
            $client->request('GET', '/customer/billing/invoices/'.$foreignId.$suffix);
            self::assertResponseStatusCodeSame(404);
        }
        $client->request('GET', '/admin/billing');
        self::assertResponseStatusCodeSame(401);
    }

    public function testPaymentWebhookIsPublicButRequiresProviderSignature(): void
    {
        $client = self::createClient();
        $client->request('POST', '/billing/webhook/stripe', [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        self::assertResponseStatusCodeSame(401);
        self::assertStringContainsString('Invalid signature', $client->getResponse()->getContent());
    }

    public function testCreditAndCancellationFormsKeepInvoiceHistory(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'admin');
        $invoice = $this->invoice($this->createUser('credit-owner@example.test', UserType::Customer));
        $id = $invoice->getId();
        $token = self::getContainer()->get(CsrfTokenManagerInterface::class)->getToken('billing')->getValue();
        $client->request('POST', '/admin/billing/invoices/'.$id.'/credit', ['_token' => $token, 'amount' => '19', 'reason' => 'Partial credit']);
        self::assertResponseStatusCodeSame(303);
        $client->request('POST', '/admin/billing/invoices/'.$id.'/cancel', ['_token' => $token, 'reason' => 'Order cancelled']);
        self::assertResponseStatusCodeSame(303);
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Order cancelled', $client->getResponse()->getContent());
        self::assertCount(0, $client->getCrawler()->filterXPath('//form[contains(@action, "/mark-paid")]'));
    }

    public function testOverCreditReturnsValidationError(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'admin');
        $invoice = $this->invoice($this->createUser('over-credit@example.test', UserType::Customer));
        $token = self::getContainer()->get(CsrfTokenManagerInterface::class)->getToken('billing')->getValue();
        $client->request('POST', '/admin/billing/invoices/'.$invoice->getId().'/credit', ['_token' => $token, 'amount' => '120', 'reason' => 'Invalid credit']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testArchivedPdfIsGeneratedByAdmin(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'admin');
        $invoice = $this->invoice($this->createUser('archive-owner@example.test', UserType::Customer));
        $id = $invoice->getId();
        $token = self::getContainer()->get(CsrfTokenManagerInterface::class)->getToken('billing')->getValue();
        $client->request('POST', '/admin/billing/invoices/'.$id.'/archive/generate', ['_token' => $token]);
        self::assertResponseStatusCodeSame(303);
        $client->request('GET', '/admin/billing/invoices/'.$id.'/archive/download');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertStringStartsWith('%PDF-', $client->getResponse()->getContent());
    }

    private function invoice(User $customer): Invoice
    {
        $customer = self::getContainer()->get(EntityManagerInterface::class)->find(User::class, $customer->getId());
        $invoice = new Invoice($customer, 'HTTP-'.bin2hex(random_bytes(5)), 11900, 'EUR', new \DateTimeImmutable('+14 days'));
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($invoice);
        $em->flush();

        return $invoice;
    }
}
