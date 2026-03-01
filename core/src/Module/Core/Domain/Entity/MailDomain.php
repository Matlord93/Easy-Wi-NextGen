<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\MailDomainRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: MailDomainRepository::class)]
#[ORM\Table(name: 'mail_domains')]
#[ORM\UniqueConstraint(name: 'uniq_mail_domain_owner_domain', columns: ['owner_id', 'domain'])]
#[ORM\UniqueConstraint(name: 'uniq_mail_domain_domain_id', columns: ['domain_id'])]
#[ORM\Index(name: 'idx_mail_domains_owner_domain', columns: ['owner_id', 'domain'])]
#[ORM\Index(name: 'idx_mail_domains_statuses', columns: ['dkim_status', 'spf_status', 'dmarc_status', 'mx_status', 'tls_status'])]
#[ORM\Index(name: 'idx_mail_domains_dns_last_checked', columns: ['dns_last_checked_at'])]
class MailDomain
{
    public const STATUS_UNKNOWN = 'unknown';
    public const STATUS_OK = 'ok';
    public const STATUS_WARNING = 'warning';
    public const STATUS_ERROR = 'error';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'owner_id', nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[Assert\NotBlank]
    #[Assert\Length(max: 253)]
    #[ORM\Column(name: 'domain', length: 253)]
    private string $domainName;

    #[ORM\OneToOne(targetEntity: Domain::class)]
    #[ORM\JoinColumn(name: 'domain_id', nullable: false, unique: true, onDelete: 'CASCADE')]
    private Domain $domain;

    #[ORM\ManyToOne(targetEntity: MailNode::class)]
    #[ORM\JoinColumn(nullable: false)]
    private MailNode $node;

    #[ORM\ManyToOne(targetEntity: QuotaPolicy::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?QuotaPolicy $quotaPolicy;

    #[Assert\Length(max: 64)]
    #[ORM\Column(length: 64)]
    private string $dkimSelector = 'default';

    #[Assert\Choice(choices: [self::STATUS_UNKNOWN, self::STATUS_OK, self::STATUS_WARNING, self::STATUS_ERROR])]
    #[ORM\Column(length: 16)]
    private string $dkimStatus = self::STATUS_UNKNOWN;

    #[Assert\Choice(choices: [self::STATUS_UNKNOWN, self::STATUS_OK, self::STATUS_WARNING, self::STATUS_ERROR])]
    #[ORM\Column(length: 16)]
    private string $spfStatus = self::STATUS_UNKNOWN;

    #[Assert\Choice(choices: [self::STATUS_UNKNOWN, self::STATUS_OK, self::STATUS_WARNING, self::STATUS_ERROR])]
    #[ORM\Column(length: 16)]
    private string $dmarcStatus = self::STATUS_UNKNOWN;

    #[Assert\Choice(choices: ['none', 'quarantine', 'reject'])]
    #[ORM\Column(length: 16)]
    private string $dmarcPolicy = 'quarantine';

    #[Assert\Choice(choices: [self::STATUS_UNKNOWN, self::STATUS_OK, self::STATUS_WARNING, self::STATUS_ERROR])]
    #[ORM\Column(length: 16)]
    private string $mxStatus = self::STATUS_UNKNOWN;

    #[Assert\Choice(choices: [self::STATUS_UNKNOWN, self::STATUS_OK, self::STATUS_WARNING, self::STATUS_ERROR])]
    #[ORM\Column(length: 16)]
    private string $tlsStatus = self::STATUS_UNKNOWN;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $dnsLastCheckedAt = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $mailEnabled = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Domain $domain, MailNode $node, ?QuotaPolicy $quotaPolicy = null)
    {
        $this->domain = $domain;
        $this->owner = $domain->getCustomer();
        $this->domainName = self::normalizeDomainName($domain->getName());
        $this->node = $node;
        $this->quotaPolicy = $quotaPolicy;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getDomainName(): string
    {
        return $this->domainName;
    }

    public function getDomain(): Domain
    {
        return $this->domain;
    }

    public function getNode(): MailNode
    {
        return $this->node;
    }

    public function getQuotaPolicy(): ?QuotaPolicy
    {
        return $this->quotaPolicy;
    }

    public function getDkimSelector(): string
    {
        return $this->dkimSelector;
    }

    public function getDkimStatus(): string
    {
        return $this->dkimStatus;
    }

    public function getSpfStatus(): string
    {
        return $this->spfStatus;
    }

    public function getDmarcStatus(): string
    {
        return $this->dmarcStatus;
    }

    public function getDmarcPolicy(): string
    {
        return $this->dmarcPolicy;
    }

    public function getMxStatus(): string
    {
        return $this->mxStatus;
    }

    public function getTlsStatus(): string
    {
        return $this->tlsStatus;
    }

    public function getDnsLastCheckedAt(): ?\DateTimeImmutable
    {
        return $this->dnsLastCheckedAt;
    }

    public function isMailEnabled(): bool
    {
        return $this->mailEnabled;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setNode(MailNode $node): void
    {
        $this->node = $node;
        $this->touch();
    }

    public function setQuotaPolicy(?QuotaPolicy $quotaPolicy): void
    {
        $this->quotaPolicy = $quotaPolicy;
        $this->touch();
    }

    public function setMailEnabled(bool $mailEnabled): void
    {
        $this->mailEnabled = $mailEnabled;
        $this->touch();
    }

    public function markDnsStatus(string $dkimStatus, string $spfStatus, string $dmarcStatus, string $mxStatus, string $tlsStatus, ?string $dmarcPolicy = null): void
    {
        $this->dkimStatus = self::normalizeStatus($dkimStatus);
        $this->spfStatus = self::normalizeStatus($spfStatus);
        $this->dmarcStatus = self::normalizeStatus($dmarcStatus);
        $this->mxStatus = self::normalizeStatus($mxStatus);
        $this->tlsStatus = self::normalizeStatus($tlsStatus);
        if ($dmarcPolicy !== null) {
            $this->dmarcPolicy = self::normalizeDmarcPolicy($dmarcPolicy);
        }
        $this->dnsLastCheckedAt = new \DateTimeImmutable();
        $this->touch();
    }

    public function rotateDkimKey(string $selector): void
    {
        $normalizedSelector = strtolower(trim($selector));
        $this->dkimSelector = $normalizedSelector !== '' ? $normalizedSelector : 'default';
        $this->dkimStatus = self::STATUS_WARNING;
        $this->touch();
    }

    public static function normalizeDomainName(string $domain): string
    {
        $candidate = strtolower(trim($domain));
        if ($candidate === '') {
            throw new \InvalidArgumentException('Domain must not be empty.');
        }

        $ascii = idn_to_ascii($candidate, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
        if (!is_string($ascii) || $ascii === '') {
            throw new \InvalidArgumentException('Domain is not valid IDN/ASCII.');
        }

        $normalized = rtrim($ascii, '.');
        if (preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9]{2,63}$/', $normalized) !== 1) {
            throw new \InvalidArgumentException('Domain format is invalid.');
        }

        return $normalized;
    }

    private static function normalizeStatus(string $value): string
    {
        $normalized = strtolower(trim($value));
        $allowed = [self::STATUS_UNKNOWN, self::STATUS_OK, self::STATUS_WARNING, self::STATUS_ERROR];

        return in_array($normalized, $allowed, true) ? $normalized : self::STATUS_UNKNOWN;
    }

    private static function normalizeDmarcPolicy(string $value): string
    {
        $normalized = strtolower(trim($value));

        return in_array($normalized, ['none', 'quarantine', 'reject'], true) ? $normalized : 'quarantine';
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
