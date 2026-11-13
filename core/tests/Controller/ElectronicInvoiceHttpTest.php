<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Domain\Entity\ElectronicInvoiceArchive;
use App\Module\Core\Domain\Entity\Invoice;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use Doctrine\ORM\EntityManagerInterface;

final class ElectronicInvoiceHttpTest extends AbstractWebTestCase
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSite();
    }

    public function testCustomerMayOnlyDownloadOwnExistingArchivesAndCannotTriggerValidation(): void
    {
        $client = self::createClient();
        $owner = $this->loginAsRole($client, 'customer');
        $own = $this->invoice($owner);
        $foreign = $this->invoice($this->createUser('foreign-einvoice@example.test', UserType::Customer));
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $contents = '<?xml version="1.0"?><synthetic-permission-test/>';
        $archive = new ElectronicInvoiceArchive($own, 'xrechnung', $contents, 'Synthetic access-control fixture');
        $em->persist($archive);
        $em->flush();
        $id = $own->getId();
        $foreignId = $foreign->getId();
        $url = '/customer/billing/invoices/'.$id.'/electronic';
        $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Synthetic access-control fixture', $client->getResponse()->getContent());
        foreach ([1, 2] as $_) {
            $client->request('GET', $url.'/xrechnung');
            self::assertResponseIsSuccessful();
            self::assertSame($contents, $client->getResponse()->getContent());
            self::assertResponseHeaderSame('X-Content-SHA256', hash('sha256', $contents));
            self::assertStringContainsString('no-store', $client->getResponse()->headers->get('Cache-Control'));
        }
        $client->request('GET', $url.'/zugferd');
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/customer/billing/invoices/'.$foreignId.'/electronic/xrechnung');
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/customer/billing/invoices/'.$foreignId.'/electronic');
        self::assertResponseStatusCodeSame(404);
        $client->request('POST', '/admin/billing/invoices/'.$id.'/electronic/xrechnung');
        self::assertContains($client->getResponse()->getStatusCode(), [401, 403]);
    }

    public function testAdminSeesConcreteMissingSnapshotFieldsAndGenerationRequiresCsrf(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'admin');
        $invoice = $this->invoice($this->createUser('legacy-einvoice@example.test', UserType::Customer));
        $url = '/admin/billing/invoices/'.$invoice->getId().'/electronic';
        $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('billing.einvoice_missing.company.street', $client->getResponse()->getContent());
        self::assertCount(2, $client->getCrawler()->filterXPath('//button[@disabled]'));
        $client->request('POST', $url.'/xrechnung');
        self::assertResponseStatusCodeSame(403);
    }

    private function invoice(User $owner): Invoice
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $invoice = new Invoice($em->find(User::class, $owner->getId()), 'EINV-'.bin2hex(random_bytes(6)), 11900, 'EUR', new \DateTimeImmutable('+14 days'));
        $em->persist($invoice);
        $em->flush();

        return $invoice;
    }
}
