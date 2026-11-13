<?php

declare(strict_types=1);

namespace App\Tests\Module\Core\Application\Billing;

use App\Module\Core\Application\Billing\ElectronicInvoiceService;
use App\Module\Core\Application\Billing\ElectronicInvoiceValidator;
use App\Module\Core\Application\Billing\InvoiceArchiveManager;
use App\Module\Core\Application\Billing\InvoiceDocumentService;
use App\Module\Core\Domain\Entity\ElectronicInvoiceArchive;
use App\Module\Core\Domain\Entity\Invoice;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ElectronicInvoiceWorkflowTest extends KernelTestCase
{
    public function testRealValidationArchivalIdempotencyAndRepeatedBlobReads(): void
    {
        $directory = getenv('EASYWI_TEST_EINVOICE_VALIDATORS');
        if (false === $directory || '' === $directory) {
            self::markTestSkipped('Install the local validators and set EASYWI_TEST_EINVOICE_VALIDATORS.');
        }
        $previous = [];
        foreach (['DATABASE_URL' => 'sqlite:///:memory:', 'EASYWI_DB_CONFIG_PATH' => '/tmp/einvoice-workflow-no-config.json'] as $key => $value) {
            $previous[$key] = [$_SERVER[$key] ?? null, $_ENV[$key] ?? null];
            $_SERVER[$key] = $_ENV[$key] = $value;
        }
        self::ensureKernelShutdown();
        try {
            self::bootKernel();
            $c = self::getContainer();
            $c->set(ElectronicInvoiceValidator::class, new ElectronicInvoiceValidator($directory));
            $em = $c->get(EntityManagerInterface::class);
            (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
            $invoice = ElectronicInvoiceBuilderTest::invoice();
            $owner = $invoice->getCustomer();
            $owner->setPasswordHash(password_hash('synthetic-workflow-only', PASSWORD_BCRYPT));
            $admin = new User('einvoice-admin@example.test', UserType::Admin);
            $admin->setPasswordHash(password_hash('synthetic-workflow-only', PASSWORD_BCRYPT));
            foreach ([$owner, $admin, $invoice] as $entity) {
                $em->persist($entity);
            }
            $em->flush();
            $pdf = $c->get(InvoiceDocumentService::class)->generate($invoice, Request::create('https://panel.example.test'));
            $original = $c->get(InvoiceArchiveManager::class)->archiveInvoiceContent($invoice, $pdf, 'original.pdf', 'application/pdf', $admin);
            $service = $c->get(ElectronicInvoiceService::class);
            $xml = $service->generate($invoice, 'xrechnung', $admin);
            $zugferd = $service->generate($invoice, 'zugferd', $admin);
            self::assertSame($xml->getId(), $service->generate($invoice, 'xrechnung', $admin)->getId());
            self::assertSame($zugferd->getId(), $service->generate($invoice, 'zugferd', $admin)->getId());
            self::assertSame(hash('sha256', $pdf), $original->getPdfHash());
            self::assertSame($pdf, $original->getPdfContents());
            self::assertSame(2, $em->getRepository(ElectronicInvoiceArchive::class)->count([]));
            $before = $xml->getContents();
            $invoice->markPaid(new \DateTimeImmutable());
            $invoice->extendDueDate(7);
            $em->flush();
            $id = $invoice->getId();
            $em->clear();
            $reloaded = $em->getRepository(ElectronicInvoiceArchive::class)->findOneBy(['invoice' => $em->find(Invoice::class, $id), 'format' => 'xrechnung']);
            self::assertSame($before, $reloaded->getContents());
            self::assertSame($before, $reloaded->getContents());
            self::assertSame(ElectronicInvoiceValidator::VERSION, $reloaded->getValidationVersion());
        } finally {
            self::ensureKernelShutdown();
            foreach ($previous as $key => [$server, $env]) {
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
        }
    }
}
