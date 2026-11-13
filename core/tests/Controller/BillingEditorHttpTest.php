<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Application\Billing\InvoiceDesign;
use App\Module\Core\Domain\Entity\Invoice;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class BillingEditorHttpTest extends AbstractWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSite();
    }

    public function testMultipleItemsAreCalculatedAndShownInOrderAndArchiveIsImmutable(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'admin');
        $customer = $this->createUser('multi-billing@example.test', UserType::Customer);
        $number = 'MULTI-'.bin2hex(random_bytes(6));
        $body = $this->invoiceBody($customer, $number);
        $client->request('POST', '/admin/billing/invoices', $body + ['amount' => '0.01', 'gross_cents' => 1]);
        self::assertResponseStatusCodeSame(303);
        $location = $client->getResponse()->headers->get('Location');
        $invoice = $this->em()->getRepository(Invoice::class)->findOneBy(['number' => $number]);
        self::assertSame(25405, $invoice->getAmountTotalCents());
        self::assertSame(21500, $invoice->getBillingDetails()['net_cents']);
        self::assertSame(['Hosting <test>', 'Einrichtung'], array_column($invoice->getBillingDetails()['items'], 'description'));
        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filterXPath('//*[@data-invoice-items]//tbody/tr'));
        self::assertStringContainsString('Hosting &lt;test&gt;', $client->getResponse()->getContent());
        $client->request('GET', $location.'/pdf');
        $pdf = $client->getResponse()->getContent();
        self::assertStringStartsWith('%PDF-', $pdf);
        $settings = self::getContainer()->get(AppSettingsService::class);
        $settings->updateSettings([AppSettingsService::KEY_INVOICE_LAYOUT => '<h1>Changed template</h1>']);
        $client->request('GET', $location.'/pdf');
        self::assertSame($pdf, $client->getResponse()->getContent());
        $client->request('POST', '/admin/billing/invoices', $body);
        self::assertResponseStatusCodeSame(422);
    }

    public function testMalformedItemsNeverFallBackToForgedTotal(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'admin');
        $customer = $this->createUser('bad-items@example.test', UserType::Customer);
        foreach ([null, 'bad', [], [['description' => 'Incomplete']]] as $items) {
            $number = 'BAD-'.bin2hex(random_bytes(6));
            $body = array_replace($this->invoiceBody($customer, $number), ['items' => $items, 'amount' => '119', 'description' => 'Forged legacy fallback']);
            $client->request('POST', '/admin/billing/invoices', $body);
            self::assertResponseStatusCodeSame(422);
            self::assertNull($this->em()->getRepository(Invoice::class)->findOneBy(['number' => $number]));
        }
    }

    public function testPreviewDoesNotSaveSettingsAndRequiresCsrf(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'admin');
        $crawler = $client->request('GET', '/admin/billing/design');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filterXPath('//iframe[@sandbox]'));
        $before = self::getContainer()->get(AppSettingsService::class)->getInvoiceLayout();
        $body = ['mode' => 'visual', 'design' => array_replace(InvoiceDesign::defaults(), ['accent' => '#aabbcc', 'heading' => '{{ source("/etc/passwd") }}'])];
        $client->request('POST', '/admin/billing/design/preview', $body);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', '/admin/billing/design/preview', $body + ['_token' => $this->token()]);
        self::assertResponseIsSuccessful();
        $result = json_decode($client->getResponse()->getContent(), true);
        self::assertStringContainsString('#aabbcc', $result['html']);
        self::assertStringContainsString('Content-Security-Policy', $result['html']);
        self::assertStringContainsString('&#123;&#123;', $result['html']);
        self::assertSame($before, self::getContainer()->get(AppSettingsService::class)->getInvoiceLayout());
        self::assertStringContainsString('no-store', $client->getResponse()->headers->get('Cache-Control'));
    }

    public function testVisualAndCustomModesSaveAndInvalidCodeIsRetainedForCorrection(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'admin');
        $options = array_replace(InvoiceDesign::defaults(), ['accent' => '#123456', 'font' => 'serif']);
        $client->request('POST', '/admin/billing/design', ['_token' => $this->token(), 'mode' => 'visual', 'design' => $options]);
        self::assertResponseStatusCodeSame(303);
        self::assertSame('visual', self::getContainer()->get(InvoiceDesign::class)->current()['mode']);
        $valid = '{% for item in invoice.items %}<p>{{ item.description }}</p>{% endfor %}';
        $client->request('POST', '/admin/billing/design', ['_token' => $this->token(), 'mode' => 'custom', 'invoice_layout' => $valid]);
        self::assertResponseStatusCodeSame(303);
        self::assertSame($valid, self::getContainer()->get(AppSettingsService::class)->getInvoiceLayout());
        $invalid = "{{ source('/etc/passwd') }}";
        $crawler = $client->request('POST', '/admin/billing/design', ['_token' => $this->token(), 'mode' => 'custom', 'invoice_layout' => $invalid]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame($invalid, $crawler->filterXPath('//textarea[@name="invoice_layout"]')->text());
        self::assertSame($valid, self::getContainer()->get(AppSettingsService::class)->getInvoiceLayout());
    }

    public function testCustomerCannotAccessDesignerOrPreview(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'customer');
        foreach ([['GET','/admin/billing/design'], ['POST','/admin/billing/design'], ['POST','/admin/billing/design/preview']] as [$method,$url]) {
            $client->request($method, $url);
            self::assertContains($client->getResponse()->getStatusCode(), [401, 403]);
        }
    }

    private function invoiceBody(User $customer, string $number): array
    {
        return ['_token' => $this->token(), 'customer_id' => $customer->getId(), 'number' => $number, 'currency' => 'EUR', 'due_date' => '2027-12-01', 'tax_mode' => 'standard',
            'items' => [['description' => 'Hosting <test>', 'quantity' => '2', 'unit_price' => '119', 'tax_rate' => '19'], ['description' => 'Einrichtung', 'quantity' => '1.5', 'unit_price' => '10.70', 'tax_rate' => '7']]];
    }

    private function token(): string
    {
        return self::getContainer()->get(CsrfTokenManagerInterface::class)->getToken('billing')->getValue();
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
