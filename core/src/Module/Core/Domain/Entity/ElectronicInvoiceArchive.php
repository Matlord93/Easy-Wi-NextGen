<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'electronic_invoice_archives')]
#[ORM\UniqueConstraint(name: 'uniq_einvoice_format', columns: ['invoice_id', 'format'])]
class ElectronicInvoiceArchive
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Invoice::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Invoice $invoice;

    #[ORM\Column(length: 12)]
    private string $format;

    #[ORM\Column(type: 'blob', length: 20000000)]
    private mixed $contents;

    #[ORM\Column(length: 64)]
    private string $sha256;

    #[ORM\Column(length: 160)]
    private string $validationVersion;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Invoice $invoice, string $format, string $contents, string $validationVersion)
    {
        if (!in_array($format, ['xrechnung', 'zugferd'], true) || '' === $contents || strlen($contents) > 20_000_000) {
            throw new \InvalidArgumentException('billing.einvoice_invalid_format');
        }
        $this->invoice = $invoice;
        $this->format = $format;
        $this->contents = $contents;
        $this->sha256 = hash('sha256', $contents);
        $this->validationVersion = $validationVersion;
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

    public function getFormat(): string
    {
        return $this->format;
    }

    public function getSha256(): string
    {
        return $this->sha256;
    }

    public function getValidationVersion(): string
    {
        return $this->validationVersion;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getContents(): string
    {
        if (is_resource($this->contents)) {
            rewind($this->contents);
            $result = stream_get_contents($this->contents);
        } else {
            $result = $this->contents;
        }
        if (!is_string($result) || !hash_equals($this->sha256, hash('sha256', $result))) {
            throw new \RuntimeException('billing.einvoice_archive_corrupted');
        }

        return $result;
    }
}
