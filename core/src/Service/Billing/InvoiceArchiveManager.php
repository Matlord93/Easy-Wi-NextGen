<?php

declare(strict_types=1);

namespace App\Service\Billing;

use App\Entity\Invoice;
use App\Entity\InvoiceArchive;
use App\Entity\User;
use App\Repository\InvoiceArchiveRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class InvoiceArchiveManager
{
    public const MAX_FILE_SIZE = 10_000_000;

    public function __construct(
        private readonly InvoiceArchiveRepository $archiveRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function archiveInvoice(Invoice $invoice, UploadedFile $file, ?User $actor = null): InvoiceArchive
    {
        $existing = $this->archiveRepository->findOneBy(['invoice' => $invoice]);
        if ($existing instanceof InvoiceArchive) {
            throw new \RuntimeException('Invoice is already archived.');
        }

        $contents = file_get_contents($file->getPathname());
        if ($contents === false) {
            throw new \RuntimeException('Invoice PDF could not be read.');
        }

        $hash = hash('sha256', $contents);
        $archive = $this->persistArchive(
            $invoice,
            $contents,
            $file->getClientOriginalName() ?: sprintf('%s.pdf', $invoice->getNumber()),
            $file->getMimeType() ?? 'application/pdf',
            $file->getSize() ?? strlen($contents),
            $hash,
            $actor,
        );

        return $archive;
    }

    public function archiveInvoiceContent(
        Invoice $invoice,
        string $contents,
        string $fileName,
        string $contentType,
        ?User $actor = null,
    ): InvoiceArchive {
        $existing = $this->archiveRepository->findOneBy(['invoice' => $invoice]);
        if ($existing instanceof InvoiceArchive) {
            throw new \RuntimeException('Invoice is already archived.');
        }

        $hash = hash('sha256', $contents);

        return $this->persistArchive(
            $invoice,
            $contents,
            $fileName,
            $contentType,
            strlen($contents),
            $hash,
            $actor,
        );
    }

    private function persistArchive(
        Invoice $invoice,
        string $contents,
        string $fileName,
        string $contentType,
        int $fileSize,
        string $hash,
        ?User $actor,
    ): InvoiceArchive {
        $archive = new InvoiceArchive(
            $invoice,
            $fileName,
            $contentType,
            $fileSize,
            $hash,
            (int) $invoice->getCreatedAt()->format('Y'),
            $contents,
        );

        $this->entityManager->persist($archive);
        $this->entityManager->flush();

        $this->auditLogger->log($actor, 'billing.invoice.archived', [
            'invoice_id' => $invoice->getId(),
            'invoice_number' => $invoice->getNumber(),
            'archived_year' => $archive->getArchivedYear(),
            'pdf_hash' => $archive->getPdfHash(),
            'file_name' => $archive->getFileName(),
            'file_size' => $archive->getFileSize(),
        ]);
        $this->entityManager->flush();

        return $archive;
    }
}
