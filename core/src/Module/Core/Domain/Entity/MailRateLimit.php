<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\MailRateLimitRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MailRateLimitRepository::class)]
#[ORM\Table(name: 'mail_rate_limits')]
#[ORM\UniqueConstraint(name: 'uniq_mail_rate_limits_mailbox', columns: ['mailbox_id'])]
#[ORM\Index(name: 'idx_mail_rate_limits_counter_window', columns: ['counter_window_start'])]
#[ORM\Index(name: 'idx_mail_rate_limits_blocked_until', columns: ['blocked_until'])]
class MailRateLimit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $customer;

    #[ORM\OneToOne(targetEntity: Mailbox::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Mailbox $mailbox;

    #[ORM\Column(options: ['default' => 240])]
    private int $maxMailsPerHour = 240;

    #[ORM\Column(options: ['default' => 100])]
    private int $maxRecipientsPerMail = 100;

    #[ORM\Column(options: ['default' => 40])]
    private int $burstPerMinute = 40;

    #[ORM\Column(options: ['default' => false])]
    private bool $greylistingEnabled = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $tlsOnly = false;

    #[ORM\Column(options: ['default' => true])]
    private bool $strictSpfDkim = true;

    #[ORM\Column(length: 16, options: ['default' => 'quarantine'])]
    private string $dmarcPolicy = 'quarantine';

    #[ORM\Column]
    private \DateTimeImmutable $counterWindowStart;

    #[ORM\Column(options: ['default' => 0])]
    private int $currentCount = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $blockedUntil = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Mailbox $mailbox)
    {
        $this->mailbox = $mailbox;
        $this->customer = $mailbox->getCustomer();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->counterWindowStart = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMailbox(): Mailbox
    {
        return $this->mailbox;
    }

    public function getMaxMailsPerHour(): int
    {
        return $this->maxMailsPerHour;
    }

    public function getMaxRecipientsPerMail(): int
    {
        return $this->maxRecipientsPerMail;
    }

    public function getCounterWindowStart(): \DateTimeImmutable
    {
        return $this->counterWindowStart;
    }

    public function getCurrentCount(): int
    {
        return $this->currentCount;
    }

    public function getBlockedUntil(): ?\DateTimeImmutable
    {
        return $this->blockedUntil;
    }

    public function configure(int $maxMailsPerHour, int $maxRecipientsPerMail, int $burstPerMinute): void
    {
        $this->maxMailsPerHour = max(1, $maxMailsPerHour);
        $this->maxRecipientsPerMail = max(1, $maxRecipientsPerMail);
        $this->burstPerMinute = max(1, $burstPerMinute);
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function configureSecurity(bool $greylistingEnabled, bool $tlsOnly, bool $strictSpfDkim, string $dmarcPolicy): void
    {
        $this->greylistingEnabled = $greylistingEnabled;
        $this->tlsOnly = $tlsOnly;
        $this->strictSpfDkim = $strictSpfDkim;
        $this->dmarcPolicy = in_array($dmarcPolicy, ['none', 'quarantine', 'reject'], true) ? $dmarcPolicy : 'quarantine';
        $this->updatedAt = new \DateTimeImmutable();
    }
}
