<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\GdprExportStatus;
use App\Repository\GdprExportRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GdprExportRepository::class)]
#[ORM\Table(name: 'gdpr_exports')]
#[ORM\Index(name: 'idx_gdpr_exports_customer', columns: ['customer_id'])]
#[ORM\Index(name: 'idx_gdpr_exports_status', columns: ['status'])]
#[ORM\Index(name: 'idx_gdpr_exports_expires', columns: ['expires_at'])]
class GdprExport
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $customer;

    #[ORM\Column(enumType: GdprExportStatus::class)]
    private GdprExportStatus $status;

    #[ORM\Column(length: 160)]
    private string $fileName;

    #[ORM\Column]
    private int $fileSize;

    #[ORM\Column(type: 'json')]
    private array $encryptedPayload;

    #[ORM\Column]
    private \DateTimeImmutable $requestedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $readyAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    /**
     * @param array{key_id: string, nonce: string, ciphertext: string} $encryptedPayload
     */
    public function __construct(
        User $customer,
        string $fileName,
        int $fileSize,
        array $encryptedPayload,
        \DateTimeImmutable $expiresAt,
        ?\DateTimeImmutable $readyAt = null,
    ) {
        $this->customer = $customer;
        $this->fileName = $fileName;
        $this->fileSize = $fileSize;
        $this->encryptedPayload = $encryptedPayload;
        $this->requestedAt = new \DateTimeImmutable();
        $this->readyAt = $readyAt ?? $this->requestedAt;
        $this->expiresAt = $expiresAt;
        $this->status = GdprExportStatus::Ready;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCustomer(): User
    {
        return $this->customer;
    }

    public function getStatus(): GdprExportStatus
    {
        return $this->status;
    }

    public function setStatus(GdprExportStatus $status): void
    {
        $this->status = $status;
    }

    public function getFileName(): string
    {
        return $this->fileName;
    }

    public function getFileSize(): int
    {
        return $this->fileSize;
    }

    /**
     * @return array{key_id: string, nonce: string, ciphertext: string}
     */
    public function getEncryptedPayload(): array
    {
        return $this->encryptedPayload;
    }

    public function getRequestedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function getReadyAt(): ?\DateTimeImmutable
    {
        return $this->readyAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now || $this->status === GdprExportStatus::Expired;
    }
}
