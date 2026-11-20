<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/** @psalm-api Durable registrar outbox: ambiguous paid operations require reconciliation, never blind replay. */
#[ORM\Entity, ORM\Table(name: 'registrar_operations')]
#[ORM\UniqueConstraint(name: 'uniq_registrar_operation_key', columns: ['request_key'])]
#[ORM\Index(name: 'idx_registrar_operation_work', columns: ['status', 'checked_at'])]
class RegistrarOperation
{
    #[ORM\Id, ORM\Column(length: 32)] private string $id;
    #[ORM\Column(length: 24)] private string $status = 'queued';
    #[ORM\Column(length: 64, nullable: true)] private ?string $jobId = null;
    #[ORM\Column(length: 120, nullable: true)] private ?string $error = null;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $checkedAt = null;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $completedAt = null;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $observedExpiry = null;
    #[ORM\Column] private \DateTimeImmutable $createdAt;
    public function __construct(
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false)] private ShopContract $contract,
        #[ORM\Column(length: 16)] private string $action,
        #[ORM\Column(length: 100)] private string $requestKey,
        #[ORM\Column(length: 64)] private string $identity,
        #[ORM\Column(type: 'text', nullable: true)] private ?string $authinfoEncrypted = null,
    ) {
        $this->id = bin2hex(random_bytes(16));
        $this->createdAt = new \DateTimeImmutable();
    }
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $accountedAt = null;
    public function getAccountedAt(): ?\DateTimeImmutable
    {
        return $this->accountedAt;
    }

    public function accounted(\DateTimeImmutable $now): void
    {
        $this->accountedAt = $now;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getContract(): ShopContract
    {
        return $this->contract;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getIdentity(): string
    {
        return $this->identity;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getJobId(): ?string
    {
        return $this->jobId;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getAuthinfoEncrypted(): ?string
    {
        return $this->authinfoEncrypted;
    }

    public function getObservedExpiry(): ?\DateTimeImmutable
    {
        return $this->observedExpiry;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function checked(\DateTimeImmutable $at): void
    {
        $this->checkedAt = $at;
    }
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $submittedAt = null;
    public function getSubmittedAt(): ?\DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function submitting(\DateTimeImmutable $now): void
    {
        $this->status = 'submitting';
        $this->submittedAt = $now;
    }

    public function accepted(string $id): void
    {
        $this->status = 'pending';
        $this->jobId = $id;
        $this->authinfoEncrypted = null;
    }

    public function review(string $error): void
    {
        $this->status = 'review';
        $this->error = mb_substr($error, 0, 120);
        $this->authinfoEncrypted = null;
    }

    public function failed(string $error): void
    {
        $this->status = 'failed';
        $this->error = mb_substr($error, 0, 120);
        $this->authinfoEncrypted = null;
    }

    public function succeeded(\DateTimeImmutable $now, ?\DateTimeImmutable $expiry = null): void
    {
        $this->status = 'succeeded';
        $this->completedAt = $now;
        $this->observedExpiry = $expiry;
        $this->authinfoEncrypted = null;
    }

    public function reconcile(string $id): void
    {
        if ('review' !== $this->status || !preg_match('/^[0-9]{1,18}$/D', $id)) {
            throw new \DomainException('Ungültige Registrar-Abgleichreferenz.');
        } $this->jobId = $id;
        $this->status = 'pending';
        $this->error = null;
    }
}
