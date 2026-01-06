<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MailAliasRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MailAliasRepository::class)]
#[ORM\Table(name: 'mail_aliases')]
class MailAlias
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $customer;

    #[ORM\ManyToOne(targetEntity: Domain::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Domain $domain;

    #[ORM\Column(length: 190)]
    private string $localPart;

    #[ORM\Column(length: 255)]
    private string $address;

    /**
     * @var string[]
     */
    #[ORM\Column(type: 'json')]
    private array $destinations = [];

    #[ORM\Column]
    private bool $enabled = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param string[] $destinations
     */
    public function __construct(Domain $domain, string $localPart, array $destinations, bool $enabled = true)
    {
        $this->setIdentity($domain, $localPart);
        $this->destinations = $destinations;
        $this->enabled = $enabled;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCustomer(): User
    {
        return $this->customer;
    }

    public function getDomain(): Domain
    {
        return $this->domain;
    }

    public function getLocalPart(): string
    {
        return $this->localPart;
    }

    public function getAddress(): string
    {
        return $this->address;
    }

    /**
     * @return string[]
     */
    public function getDestinations(): array
    {
        return $this->destinations;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setIdentity(Domain $domain, string $localPart): void
    {
        $this->domain = $domain;
        $this->customer = $domain->getCustomer();
        $this->localPart = $localPart;
        $this->address = sprintf('%s@%s', $localPart, $domain->getName());
        $this->touch();
    }

    /**
     * @param string[] $destinations
     */
    public function setDestinations(array $destinations): void
    {
        $this->destinations = $destinations;
        $this->touch();
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
