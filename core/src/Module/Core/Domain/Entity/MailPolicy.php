<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\MailPolicyRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: MailPolicyRepository::class)]
#[ORM\Table(name: 'mail_policies')]
#[ORM\UniqueConstraint(name: 'uniq_mail_policy_domain', columns: ['domain_id'])]
#[ORM\Index(name: 'idx_mail_policy_owner_domain', columns: ['owner_id', 'domain_id'])]
class MailPolicy
{
    public const SPAM_LOW = 'low';
    public const SPAM_MED = 'med';
    public const SPAM_HIGH = 'high';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'owner_id', nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\OneToOne(targetEntity: Domain::class)]
    #[ORM\JoinColumn(name: 'domain_id', nullable: false, unique: true, onDelete: 'CASCADE')]
    private Domain $domain;

    #[ORM\Column(options: ['default' => false])]
    private bool $requireTls = false;
    #[ORM\Column(options: ['default' => true])]
    private bool $smtpEnabled = true;

    #[Assert\Positive]
    #[ORM\Column(options: ['default' => 100])]
    private int $maxRecipients = 100;

    #[Assert\Positive]
    #[ORM\Column(options: ['default' => 500])]
    private int $maxHourlyEmails = 500;

    #[ORM\Column(options: ['default' => false])]
    private bool $allowExternalForwarding = false;

    #[Assert\Choice(choices: [self::SPAM_LOW, self::SPAM_MED, self::SPAM_HIGH])]
    #[ORM\Column(length: 8, options: ['default' => self::SPAM_MED])]
    private string $spamProtectionLevel = self::SPAM_MED;

    #[ORM\Column(options: ['default' => true])]
    private bool $greylistingEnabled = true;
    #[ORM\Column(options: ['default' => true])]
    private bool $abusePolicyEnabled = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Domain $domain)
    {
        $this->domain = $domain;
        $this->owner = $domain->getCustomer();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDomain(): Domain
    {
        return $this->domain;
    }

    public function isRequireTls(): bool
    {
        return $this->requireTls;
    }

    public function getMaxRecipients(): int
    {
        return $this->maxRecipients;
    }

    public function getMaxHourlyEmails(): int
    {
        return $this->maxHourlyEmails;
    }

    public function isAllowExternalForwarding(): bool
    {
        return $this->allowExternalForwarding;
    }

    public function getSpamProtectionLevel(): string
    {
        return $this->spamProtectionLevel;
    }

    public function isGreylistingEnabled(): bool
    {
        return $this->greylistingEnabled;
    }

    public function isSmtpEnabled(): bool
    {
        return $this->smtpEnabled;
    }

    public function isAbusePolicyEnabled(): bool
    {
        return $this->abusePolicyEnabled;
    }

    public function apply(
        bool $requireTls,
        bool $smtpEnabled,
        int $maxRecipients,
        int $maxHourlyEmails,
        bool $abusePolicyEnabled,
        bool $allowExternalForwarding,
        string $spamProtectionLevel,
        bool $greylistingEnabled,
    ): void {
        $this->requireTls = $requireTls;
        $this->smtpEnabled = $smtpEnabled;
        $this->maxRecipients = max(0, $maxRecipients);
        $this->maxHourlyEmails = max(0, $maxHourlyEmails);
        $this->abusePolicyEnabled = $abusePolicyEnabled;
        $this->allowExternalForwarding = $allowExternalForwarding;
        $this->spamProtectionLevel = in_array($spamProtectionLevel, [self::SPAM_LOW, self::SPAM_MED, self::SPAM_HIGH], true) ? $spamProtectionLevel : self::SPAM_MED;
        $this->greylistingEnabled = $greylistingEnabled;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function toArray(): array
    {
        return [
            'require_tls' => $this->requireTls,
            'smtp_enabled' => $this->smtpEnabled,
            'max_recipients' => $this->maxRecipients,
            'max_hourly_emails' => $this->maxHourlyEmails,
            'abuse_policy_enabled' => $this->abusePolicyEnabled,
            'allow_external_forwarding' => $this->allowExternalForwarding,
            'spam_protection_level' => $this->spamProtectionLevel,
            'greylisting_enabled' => $this->greylistingEnabled,
            'domain_id' => $this->domain->getId(),
            'domain' => $this->domain->getName(),
        ];
    }
}
