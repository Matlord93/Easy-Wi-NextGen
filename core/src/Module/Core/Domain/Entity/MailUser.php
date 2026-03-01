<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\MailUserRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MailUserRepository::class)]
#[ORM\Table(name: 'mail_users')]
#[ORM\UniqueConstraint(name: 'uniq_mail_users_address', columns: ['address'])]
#[ORM\UniqueConstraint(name: 'uniq_mail_users_mailbox', columns: ['mailbox_id'])]
#[ORM\Index(name: 'idx_mail_users_domain_enabled', columns: ['domain_id', 'enabled'])]
class MailUser
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: Mailbox::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Mailbox $mailbox;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $customer;

    #[ORM\ManyToOne(targetEntity: Domain::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Domain $domain;

    #[ORM\Column(length: 190)]
    private string $localPart;

    #[ORM\Column(length: 255)]
    private string $address;

    #[ORM\Column(length: 255)]
    private string $passwordHash;

    #[ORM\Column]
    private int $quotaMb;

    #[ORM\Column(options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastAuthAt = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $lastAuthIp = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Mailbox $mailbox)
    {
        $this->mailbox = $mailbox;
        $this->customer = $mailbox->getCustomer();
        $this->domain = $mailbox->getDomain();
        $this->localPart = $mailbox->getLocalPart();
        $this->address = $mailbox->getAddress();
        $this->passwordHash = $mailbox->getPasswordHash();
        $this->quotaMb = $mailbox->getQuota();
        $this->enabled = $mailbox->isEnabled();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function syncFromMailbox(Mailbox $mailbox): void
    {
        $this->mailbox = $mailbox;
        $this->customer = $mailbox->getCustomer();
        $this->domain = $mailbox->getDomain();
        $this->localPart = $mailbox->getLocalPart();
        $this->address = $mailbox->getAddress();
        $this->passwordHash = $mailbox->getPasswordHash();
        $this->quotaMb = $mailbox->getQuota();
        $this->enabled = $mailbox->isEnabled();
        $this->updatedAt = new \DateTimeImmutable();
    }
}
