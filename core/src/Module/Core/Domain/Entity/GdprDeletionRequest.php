<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Module\Core\Domain\Enum\GdprDeletionStatus;
use App\Repository\GdprDeletionRequestRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GdprDeletionRequestRepository::class)]
#[ORM\Table(name: 'gdpr_deletion_requests')]
#[ORM\Index(name: 'idx_gdpr_deletion_customer', columns: ['customer_id'])]
#[ORM\Index(name: 'idx_gdpr_deletion_status', columns: ['status'])]
class GdprDeletionRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $customer;

    #[ORM\Column(enumType: GdprDeletionStatus::class)]
    private GdprDeletionStatus $status;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $jobId = null;

    #[ORM\Column]
    private \DateTimeImmutable $requestedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $processedAt = null;

    public function __construct(User $customer)
    {
        $this->customer = $customer;
        $this->status = GdprDeletionStatus::Requested;
        $this->requestedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCustomer(): User
    {
        return $this->customer;
    }

    public function getStatus(): GdprDeletionStatus
    {
        return $this->status;
    }

    public function markProcessing(string $jobId): void
    {
        $this->status = GdprDeletionStatus::Processing;
        $this->jobId = $jobId;
    }

    public function markCompleted(): void
    {
        $this->status = GdprDeletionStatus::Completed;
        $this->processedAt = new \DateTimeImmutable();
    }

    public function getJobId(): ?string
    {
        return $this->jobId;
    }

    public function getRequestedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function getProcessedAt(): ?\DateTimeImmutable
    {
        return $this->processedAt;
    }
}
