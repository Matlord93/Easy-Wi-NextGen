<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\MailForwardingRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MailForwardingRepository::class)]
#[ORM\Table(name: 'mail_forwardings')]
#[ORM\UniqueConstraint(name: 'uniq_mail_forwarding_route', columns: ['domain_id', 'source_local_part', 'destination'])]
#[ORM\Index(name: 'idx_mail_forwardings_domain_enabled', columns: ['domain_id', 'enabled'])]
class MailForwarding
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

    #[ORM\Column(length: 190)]
    private string $sourceLocalPart;

    #[ORM\Column(length: 255)]
    private string $destination;

    #[ORM\Column(options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Domain $domain, string $sourceLocalPart, string $destination, bool $enabled = true)
    {
        $this->domain = $domain;
        $this->customer = $domain->getCustomer();
        $this->sourceLocalPart = strtolower(trim($sourceLocalPart));
        $this->destination = strtolower(trim($destination));
        $this->enabled = $enabled;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }
}
