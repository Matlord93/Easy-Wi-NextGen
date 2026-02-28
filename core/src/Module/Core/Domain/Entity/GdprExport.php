<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Module\Core\Domain\Enum\GdprExportStatus;
use App\Repository\GdprExportRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GdprExportRepository::class)]
#[ORM\Table(name: 'gdpr_exports')]
#[ORM\Index(name: 'idx_gdpr_exports_customer', columns: ['customer_id'])]
#[ORM\Index(name: 'idx_gdpr_exports_status', columns: ['status'])]
#[ORM\Index(name: 'idx_gdpr_exports_expires', columns: ['expires_at'])]
class GdprExport
{
    private const DOWNLOAD_TOKEN_TTL = '+30 minutes';

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

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $downloadTokenHash = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $downloadTokenExpiresAt = null;

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
        ?GdprExportStatus $status = null,
    ) {
        $this->customer = $customer;
        $this->fileName = $fileName;
        $this->fileSize = $fileSize;
        $this->encryptedPayload = $encryptedPayload;
        $this->requestedAt = new \DateTimeImmutable();
        $this->readyAt = $readyAt;
        $this->expiresAt = $expiresAt;
        $this->status = $status ?? ($readyAt === null ? GdprExportStatus::Pending : GdprExportStatus::Ready);
    }

    public static function createPending(User $customer, string $fileName): self
    {
        return new self(
            $customer,
            $fileName,
            0,
            ['key_id' => 'pending', 'nonce' => '', 'ciphertext' => ''],
            (new \DateTimeImmutable())->modify('+7 days'),
            null,
            GdprExportStatus::Pending,
        );
    }

    public function markReady(string $fileName, int $fileSize, array $encryptedPayload, \DateTimeImmutable $expiresAt, ?\DateTimeImmutable $readyAt = null): void
    {
        if ($this->status !== GdprExportStatus::Running) {
            throw new \LogicException('Only running exports can be marked ready.');
        }

        $this->fileName = $fileName;
        $this->fileSize = $fileSize;
        $this->encryptedPayload = $encryptedPayload;
        $this->expiresAt = $expiresAt;
        $this->readyAt = $readyAt ?? new \DateTimeImmutable();
        $this->status = GdprExportStatus::Ready;
    }

    public function markRunning(): void
    {
        if ($this->status !== GdprExportStatus::Pending) {
            throw new \LogicException('Only pending exports can enter running state.');
        }

        $this->status = GdprExportStatus::Running;
    }

    public function markFailed(): void
    {
        if ($this->status !== GdprExportStatus::Running) {
            throw new \LogicException('Only running exports can fail.');
        }

        $this->status = GdprExportStatus::Failed;
    }

    public function issueDownloadToken(?\DateTimeImmutable $now = null): string
    {
        $now ??= new \DateTimeImmutable();
        $token = bin2hex(random_bytes(24));

        $this->downloadTokenHash = password_hash($token, PASSWORD_DEFAULT);
        $this->downloadTokenExpiresAt = $now->modify(self::DOWNLOAD_TOKEN_TTL);

        return $token;
    }

    public function consumeValidDownloadToken(string $token, ?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();
        if ($this->downloadTokenHash === null || $this->downloadTokenExpiresAt === null) {
            return false;
        }

        if ($this->downloadTokenExpiresAt <= $now) {
            $this->revokeDownloadToken();
            return false;
        }

        if (!password_verify($token, $this->downloadTokenHash)) {
            return false;
        }

        $this->revokeDownloadToken();

        return true;
    }

    public function revokeDownloadToken(): void
    {
        $this->downloadTokenHash = null;
        $this->downloadTokenExpiresAt = null;
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
    /** @return array{key_id: string, nonce: string, ciphertext: string} */
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
    public function getDownloadTokenExpiresAt(): ?\DateTimeImmutable
    {
        return $this->downloadTokenExpiresAt;
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now || $this->status === GdprExportStatus::Expired;
    }
}
