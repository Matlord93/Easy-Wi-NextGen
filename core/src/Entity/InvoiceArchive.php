<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\InvoiceArchiveRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InvoiceArchiveRepository::class)]
#[ORM\Table(name: 'invoice_archives')]
#[ORM\Index(name: 'idx_invoice_archives_year', columns: ['archived_year'])]
class InvoiceArchive
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: Invoice::class)]
    #[ORM\JoinColumn(nullable: false, unique: true)]
    private Invoice $invoice;

    #[ORM\Column(length: 160)]
    private string $fileName;

    #[ORM\Column(length: 80)]
    private string $contentType;

    #[ORM\Column]
    private int $fileSize;

    #[ORM\Column(length: 64)]
    private string $pdfHash;

    #[ORM\Column(type: 'blob')]
    private mixed $pdfData;

    #[ORM\Column]
    private int $archivedYear;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Invoice $invoice,
        string $fileName,
        string $contentType,
        int $fileSize,
        string $pdfHash,
        int $archivedYear,
        string $pdfData,
    ) {
        $this->invoice = $invoice;
        $this->fileName = $fileName;
        $this->contentType = $contentType;
        $this->fileSize = $fileSize;
        $this->pdfHash = $pdfHash;
        $this->archivedYear = $archivedYear;
        $this->pdfData = $pdfData;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getInvoice(): Invoice
    {
        return $this->invoice;
    }

    public function getFileName(): string
    {
        return $this->fileName;
    }

    public function getContentType(): string
    {
        return $this->contentType;
    }

    public function getFileSize(): int
    {
        return $this->fileSize;
    }

    public function getPdfHash(): string
    {
        return $this->pdfHash;
    }

    public function getArchivedYear(): int
    {
        return $this->archivedYear;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getPdfContents(): string
    {
        if (is_resource($this->pdfData)) {
            $contents = stream_get_contents($this->pdfData);
            return $contents === false ? '' : $contents;
        }

        return (string) $this->pdfData;
    }
}
