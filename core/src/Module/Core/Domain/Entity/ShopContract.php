<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Module\Core\Domain\Enum\ShopProductKind;
use Doctrine\ORM\Mapping as ORM;

/** @psalm-api Doctrine persists contracts; customer/admin views read their state. */
#[ORM\Entity, ORM\Table(name: 'shop_contracts')]
#[ORM\Index(name: 'idx_contract_work', columns: ['status', 'checked_at'])]
#[ORM\Index(name: 'idx_contract_resource', columns: ['kind', 'resource_id'])]
#[ORM\Index(name: 'idx_contract_cleanup', columns: ['status', 'cleanup_checked_at'])]
#[ORM\Index(name: 'idx_contract_account', columns: ['account_id', 'status'])]
#[ORM\UniqueConstraint(name: 'uniq_contract_reservation', columns: ['reservation_key'])]
class ShopContract
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\Column(length: 24)] private string $status = 'pending';
    #[ORM\Column(length: 120, nullable: true)] private ?string $resourceId = null;
    #[ORM\Column(length: 64, nullable: true)] private ?string $jobId = null;
    #[ORM\Column(length: 16, nullable: true)] private ?string $jobSystem = null;
    /** @var list<string>|null */
    #[ORM\Column(type: 'json', nullable: true)] private ?array $auxiliaryJobs = null;
    /** @var list<string>|null */
    #[ORM\Column(type: 'json', nullable: true)] private ?array $stopJobs = null;
    /** @return list<string> */
    public function getAuxiliaryJobs(): array
    {
        return $this->auxiliaryJobs ?? [];
    }

    /** @param list<string> $ids */
    public function auxiliaryJobs(array $ids): void
    {
        $this->auxiliaryJobs = $ids;
    }

    /** @return list<string> */
    public function getStopJobs(): array
    {
        return $this->stopJobs ?? [];
    }

    /** @param list<string> $ids */
    public function stopJobs(array $ids): void
    {
        $this->stopJobs = $ids;
        $this->touch();
    }
    #[ORM\Column(length: 64, nullable: true)] private ?string $stopJobId = null;
    #[ORM\Column(length: 16, nullable: true)] private ?string $stopJobSystem = null;
    public function retryStop(): void
    {
        ++$this->stopAttempts;
        $this->stopJobId = null;
        $this->stopJobSystem = null;
        $this->stopJobs = null;
        $this->touch();
    }

    public function getStopJobId(): ?string
    {
        return $this->stopJobId;
    }

    public function getStopJobSystem(): ?string
    {
        return $this->stopJobSystem;
    }

    public function stoppingJob(string $id, string $system): void
    {
        $this->stopJobId = $id;
        $this->stopJobSystem = $system;
        $this->touch();
    }
    #[ORM\Column(length: 255, nullable: true)] private ?string $reservationKey = null;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $startedAt = null;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $endedAt = null;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $cancelledAt = null;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $termEndsAt = null;
    #[ORM\Column] private int $chargedMinutes = 0;
    #[ORM\Column(type: 'bigint')] private int $pendingUnits = 0;
    #[ORM\Column(type: 'bigint')] private int $reservedUnits = 0;
    #[ORM\Column] private int $invoicedMinutes = 0;
    #[ORM\Column(length: 120, nullable: true)] private ?string $error = null;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $checkedAt = null;
    public function checked(\DateTimeImmutable $now): void
    {
        $this->checkedAt = $now;
    }

    public function domainTermEnds(\DateTimeImmutable $at): void
    {
        $this->termEndsAt = $at;
        $this->error = null;
        $this->touch();
    }
    #[ORM\Column] private \DateTimeImmutable $createdAt;
    #[ORM\Column] private \DateTimeImmutable $updatedAt;
    /** @param array<string,int|string|bool> $configuration */
    public function __construct(
        #[ORM\OneToOne(inversedBy: 'contract'), ORM\JoinColumn(nullable: false)] private ShopOrder $order,
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false)] private RentalAccount $account,
        #[ORM\Column(enumType: ShopProductKind::class)] private ShopProductKind $kind,
        #[ORM\Column(length: 12)] private string $billingMode,
        #[ORM\Column(type: 'json')] private array $configuration,
        #[ORM\Column] private int $monthlyCents,
    ) {
        if (!in_array($billingMode, ['wallet', 'postpaid'], true) || $monthlyCents < 0 || $monthlyCents > 10_000_000) {
            throw new \InvalidArgumentException('Invalid rental contract.');
        }
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $provisioningDeadlineAt = null;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $nextAttemptAt = null;
    #[ORM\Column(options: ['default' => 0])] private int $provisionAttempts = 0;
    #[ORM\Column(options: ['default' => 0])] private int $stopAttempts = 0;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $deleteAfterAt = null;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $purgedAt = null;
    #[ORM\Column(options: ['default' => false])] private bool $retentionHold = false;
    /** @var list<array{system:string,id:string}>|null */
    #[ORM\Column(type: 'json', nullable: true)] private ?array $cleanupJobs = null;
    public function getProvisioningDeadlineAt(): ?\DateTimeImmutable
    {
        return $this->provisioningDeadlineAt;
    }

    public function getNextAttemptAt(): ?\DateTimeImmutable
    {
        return $this->nextAttemptAt;
    }

    public function getProvisionAttempts(): int
    {
        return $this->provisionAttempts;
    }

    public function getStopAttempts(): int
    {
        return $this->stopAttempts;
    }

    public function getDeleteAfterAt(): ?\DateTimeImmutable
    {
        return $this->deleteAfterAt;
    }

    public function getPurgedAt(): ?\DateTimeImmutable
    {
        return $this->purgedAt;
    }

    public function isRetentionHold(): bool
    {
        return $this->retentionHold;
    }

    public function retain(bool $hold): void
    {
        if ($hold && ([] !== $this->getCleanupJobs() || [] !== $this->getBackupCleanupJobs() || null !== $this->purgedAt || null !== $this->backupsPurgedAt)) {
            throw new \DomainException('Die Bereinigung wurde bereits beauftragt. Ein Aufbewahrungsschutz kann jetzt nicht mehr zugesichert werden. Bitte den Auftragsstatus prüfen.');
        }
        $this->retentionHold = $hold;
        $this->touch();
    }

    public function scheduleDeletion(\DateTimeImmutable $at): void
    {
        if (null === $this->deleteAfterAt || $this->deleteAfterAt < $at) {
            $this->deleteAfterAt = $at;
        }
    }

    public function purged(\DateTimeImmutable $at): void
    {
        $this->purgedAt = $at;
        $this->touch();
    }

    /** @return list<array{system:string,id:string}> */
    public function getCleanupJobs(): array
    {
        return $this->cleanupJobs ?? [];
    }

    /** @param list<array{system:string,id:string}> $jobs */
    public function cleanupJobs(array $jobs): void
    {
        $this->cleanupJobs = $jobs;
        $this->touch();
    }

    public function attempted(\DateTimeImmutable $now, int $timeoutMinutes, int $retryMinutes): void
    {
        ++$this->provisionAttempts;
        $this->provisioningDeadlineAt ??= $now->modify('+'.$timeoutMinutes.' minutes');
        $this->nextAttemptAt = $now->modify('+'.$retryMinutes.' minutes');
    }

    public function retryOperations(): void
    {
        $this->stopAttempts = 0;
        $this->error = null;
        $this->touch();
    }

    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $cleanupCheckedAt = null;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $deletionNoticeAt = null;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $backupsPurgedAt = null;
    /** @var list<array{system:string,id:string}>|null */
    #[ORM\Column(type: 'json', nullable: true)] private ?array $backupCleanupJobs = null;
    public function cleanupChecked(\DateTimeImmutable $now): void
    {
        $this->cleanupCheckedAt = $now;
    }

    public function getDeletionNoticeAt(): ?\DateTimeImmutable
    {
        return $this->deletionNoticeAt;
    }

    public function deletionAnnounced(\DateTimeImmutable $now): void
    {
        $this->deletionNoticeAt ??= $now;
    }

    public function getBackupsPurgedAt(): ?\DateTimeImmutable
    {
        return $this->backupsPurgedAt;
    }

    public function backupsPurged(\DateTimeImmutable $now): void
    {
        $this->backupsPurgedAt = $now;
    }

    /** @return list<array{system:string,id:string}> */
    public function getBackupCleanupJobs(): array
    {
        return $this->backupCleanupJobs ?? [];
    }

    /** @param list<array{system:string,id:string}> $jobs */
    public function backupCleanupJobs(array $jobs): void
    {
        $this->backupCleanupJobs = $jobs;
    }

    public function trackProvisioning(string $id, string $system): void
    {
        $this->jobId = $id;
        $this->jobSystem = $system;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrder(): ShopOrder
    {
        return $this->order;
    }

    public function getAccount(): RentalAccount
    {
        return $this->account;
    }

    public function getCustomer(): User
    {
        return $this->order->getCustomer();
    }

    public function getKind(): ShopProductKind
    {
        return $this->kind;
    }

    public function getBillingMode(): string
    {
        return $this->billingMode;
    }

    /** @return array<string,int|string|bool> */
    public function getConfiguration(): array
    {
        return $this->configuration;
    }

    public function getMonthlyCents(): int
    {
        return $this->monthlyCents;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getResourceId(): ?string
    {
        return $this->resourceId;
    }

    public function getJobId(): ?string
    {
        return $this->jobId;
    }

    public function getJobSystem(): ?string
    {
        return $this->jobSystem;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getEndedAt(): ?\DateTimeImmutable
    {
        return $this->endedAt;
    }

    public function getCancelledAt(): ?\DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function getTermEndsAt(): ?\DateTimeImmutable
    {
        return $this->termEndsAt;
    }

    public function getChargedMinutes(): int
    {
        return $this->chargedMinutes;
    }

    public function getPendingUnits(): int
    {
        return $this->pendingUnits;
    }

    public function getReservedUnits(): int
    {
        return $this->reservedUnits;
    }

    public function getInvoicedMinutes(): int
    {
        return $this->invoicedMinutes;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function reserve(int $units): void
    {
        $this->reservedUnits = $units;
    }

    public function releaseReservation(): int
    {
        $units = $this->reservedUnits;
        $this->reservedUnits = 0;

        return $units;
    }

    public function claimName(string $key): void
    {
        $this->reservationKey = $key;
    }

    public function bind(string $id, ?string $jobId, ?string $system): void
    {
        $this->resourceId = $id;
        $this->jobId = $jobId;
        $this->jobSystem = $system;
        $this->status = 'provisioning';
        $this->touch();
    }

    public function activate(\DateTimeImmutable $at): void
    {
        if (null !== $this->startedAt) {
            return;
        } $this->startedAt = $at;
        $this->status = 'active';
        $this->error = null;
        if (ShopProductKind::Domain === $this->kind) {
            $this->termEndsAt = \App\Module\Core\Domain\ValueObject\BillingPeriod::addMonths($at, (int) ($this->configuration['term_months'] ?? 12));
        } $this->touch();
    }

    public function charge(int $minutes, int $units): void
    {
        if ($minutes < $this->chargedMinutes || $units < 0 || $units > PHP_INT_MAX - $this->pendingUnits) {
            throw new \LogicException('Invalid metering update.');
        } $this->chargedMinutes = $minutes;
        $this->pendingUnits += $units;
        $this->touch();
    }

    public function invoiced(): void
    {
        $this->pendingUnits = 0;
        $this->invoicedMinutes = $this->chargedMinutes;
        $this->touch();
    }

    public function requestCancellation(\DateTimeImmutable $at): void
    {
        $this->cancelledAt ??= $at;
        $this->touch();
    }

    public function end(\DateTimeImmutable $at, string $reason): void
    {
        $this->endedAt ??= $at;
        $this->status = 'stopping';
        $this->error = $reason;
        $this->touch();
    }

    public function stopped(): void
    {
        $this->status = 'ended';
        $this->reservationKey = null;
        $this->touch();
    }

    public function fail(string $error): void
    {
        $this->error = mb_substr($error, 0, 120);
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
