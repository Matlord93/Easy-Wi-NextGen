<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\MailDkimKeyRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MailDkimKeyRepository::class)]
#[ORM\Table(name: 'mail_dkim_keys')]
#[ORM\UniqueConstraint(name: 'uniq_mail_dkim_domain_selector', columns: ['domain_id', 'selector'])]
#[ORM\Index(name: 'idx_mail_dkim_domain_active', columns: ['domain_id', 'active'])]
class MailDkimKey
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $customer;

    #[ORM\ManyToOne(targetEntity: Domain::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Domain $domain;

    #[ORM\Column(length: 64)]
    private string $selector;

    #[ORM\Column(type: 'text')]
    private string $publicKey;

    #[ORM\Column(length: 16, options: ['default' => 'rsa'])]
    private string $algorithm = 'rsa';

    #[ORM\Column(options: ['default' => 2048])]
    private int $keyBits = 2048;

    #[ORM\Column(length: 64)]
    private string $fingerprintSha256;

    #[ORM\Column(length: 255)]
    private string $privateKeyPath;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $agentNodeId = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $rotatedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deactivatedAt = null;

    public function __construct(
        Domain $domain,
        string $selector,
        string $publicKey,
        string $privateKeyPath,
        string $fingerprintSha256,
        ?string $agentNodeId = null,
    ) {
        $this->domain = $domain;
        $this->customer = $domain->getCustomer();
        $this->selector = strtolower(trim($selector));
        $this->publicKey = trim($publicKey);
        $this->privateKeyPath = trim($privateKeyPath);
        $this->fingerprintSha256 = strtolower(trim($fingerprintSha256));
        $this->agentNodeId = $agentNodeId !== null ? trim($agentNodeId) : null;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function deactivate(): void
    {
        $this->active = false;
        $this->deactivatedAt = new \DateTimeImmutable();
    }
}
