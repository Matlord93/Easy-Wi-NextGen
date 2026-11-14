<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Billing;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Domain\Entity\ElectronicInvoiceArchive;
use App\Module\Core\Domain\Entity\Invoice;
use App\Module\Core\Domain\Entity\User;
use App\Repository\InvoiceArchiveRepository;
use Doctrine\ORM\EntityManagerInterface;
use horstoeko\zugferd\ZugferdDocumentPdfBuilder;
use Symfony\Component\Lock\LockFactory;

final readonly class ElectronicInvoiceService
{
    public function __construct(private EntityManagerInterface $em, private ElectronicInvoiceBuilder $builder, private ElectronicInvoiceValidator $validator, private InvoiceArchiveRepository $pdfs, private LockFactory $locks, private AuditLogger $audit)
    {
    }

    /** @return list<ElectronicInvoiceArchive> */
    public function archives(Invoice $invoice): array
    {
        return $this->em->getRepository(ElectronicInvoiceArchive::class)->findBy(['invoice' => $invoice], ['format' => 'ASC']);
    }

    public function generate(Invoice $invoice, string $format, User $actor): ElectronicInvoiceArchive
    {
        if (!$actor->isAdmin()) {
            throw new \DomainException('billing.einvoice_admin_required');
        }
        if (!in_array($format, ['xrechnung', 'zugferd'], true)) {
            throw new \InvalidArgumentException('billing.einvoice_invalid_format');
        }
        $existing = $this->em->getRepository(ElectronicInvoiceArchive::class)->findOneBy(['invoice' => $invoice, 'format' => $format]);
        if ($existing instanceof ElectronicInvoiceArchive) {
            return $existing;
        }
        // One validation at a time per panel worker host. Java exits after validation;
        // no daemon, recurring heap or process restart is required.
        $lock = $this->locks->createLock('billing.einvoice.validation', 150);
        if (!$lock->acquire()) {
            throw new \RuntimeException('billing.einvoice_busy');
        }
        try {
            if (!$this->validator->ready($format)) {
                throw new \RuntimeException('billing.einvoice_validator_missing');
            }
            $document = $this->builder->build($invoice, $format);
            $xml = $document->getContent();
            $contents = $xml;
            if ('zugferd' === $format) {
                $pdf = $this->pdfs->findOneBy(['invoice' => $invoice]);
                if (null === $pdf || 'application/pdf' !== $pdf->getContentType()) {
                    throw new \InvalidArgumentException('billing.einvoice_pdf_archive_required');
                }
                $original = $pdf->getPdfContents();
                if (!hash_equals($pdf->getPdfHash(), hash('sha256', $original))) {
                    throw new \RuntimeException('billing.einvoice_archive_corrupted');
                }
                try {
                    $contents = ZugferdDocumentPdfBuilder::fromPdfString($document, $original)->setPdfAConformanceLevelToBasic()->generateDocument()->downloadString();
                } catch (\Exception $exception) {
                    throw new \InvalidArgumentException('billing.einvoice_pdf_failed', 0, $exception);
                }
            }
            $this->validator->validate($xml, $format, 'zugferd' === $format ? $contents : null);

            return $this->em->wrapInTransaction(function () use ($invoice, $format, $contents, $actor): ElectronicInvoiceArchive {
                // Serialize final archival across multiple panel hosts too.
                $this->em->lock($invoice, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
                $existing = $this->em->getRepository(ElectronicInvoiceArchive::class)->findOneBy(['invoice' => $invoice, 'format' => $format]);
                if ($existing instanceof ElectronicInvoiceArchive) {
                    return $existing;
                }
                $archive = new ElectronicInvoiceArchive($invoice, $format, $contents, ElectronicInvoiceValidator::VERSION);
                $this->em->persist($archive);
                $this->em->flush();
                $this->audit->log($actor, 'billing.einvoice.archived', ['invoice_id' => $invoice->getId(), 'format' => $format, 'sha256' => $archive->getSha256(), 'validator' => $archive->getValidationVersion()]);

                return $archive;
            });
        } finally {
            $lock->release();
        }
    }
}
