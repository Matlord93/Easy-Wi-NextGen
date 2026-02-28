<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\MailDomainRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MailDomainRepository::class)]
#[ORM\Table(name: 'mail_domains')]
class MailDomain
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: Domain::class)]
    #[ORM\JoinColumn(nullable: false, unique: true)]
    private Domain $domain;

    #[ORM\ManyToOne(targetEntity: MailNode::class)]
    #[ORM\JoinColumn(nullable: false)]
    private MailNode $node;

    #[ORM\ManyToOne(targetEntity: QuotaPolicy::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?QuotaPolicy $quotaPolicy;

    #[ORM\Column(length: 64)]
    private string $dkimSelector = 'default';

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $dkimPrivateKeyPayload = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $dkimPreviousPrivateKeyPayload = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $dkimRotatedAt = null;

    #[ORM\Column(length: 16)]
    private string $dmarcPolicy = 'quarantine';

    public function __construct(Domain $domain, MailNode $node, ?QuotaPolicy $quotaPolicy = null)
    {
        $this->domain = $domain;
        $this->node = $node;
        $this->quotaPolicy = $quotaPolicy;
    }

    public function getId(): ?int
    {
        return $this->id;
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
    /** @return array{key_id: string, nonce: string, ciphertext: string}|null */
    public function getDkimPrivateKeyPayload(): ?array
    {
        return $this->dkimPrivateKeyPayload;
    }
    /** @return array{key_id: string, nonce: string, ciphertext: string}|null */
    public function getDkimPreviousPrivateKeyPayload(): ?array
    {
        return $this->dkimPreviousPrivateKeyPayload;
    }
    public function getDkimRotatedAt(): ?\DateTimeImmutable
    {
        return $this->dkimRotatedAt;
    }
    public function getDmarcPolicy(): string
    {
        return $this->dmarcPolicy;
    }

    public function setNode(MailNode $node): void
    {
        $this->node = $node;
    }

    public function setQuotaPolicy(?QuotaPolicy $quotaPolicy): void
    {
        $this->quotaPolicy = $quotaPolicy;
    }

    /**
     * @param array{key_id: string, nonce: string, ciphertext: string} $privateKeyPayload
     */
    public function rotateDkimKey(string $selector, array $privateKeyPayload): void
    {
        $this->dkimPreviousPrivateKeyPayload = $this->dkimPrivateKeyPayload;
        $this->dkimPrivateKeyPayload = $privateKeyPayload;
        $this->dkimSelector = strtolower(trim($selector)) !== '' ? strtolower(trim($selector)) : 'default';
        $this->dkimRotatedAt = new \DateTimeImmutable();
    }
}
