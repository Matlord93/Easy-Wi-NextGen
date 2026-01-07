<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TenantRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TenantRepository::class)]
#[ORM\Table(name: 'tenants')]
class Tenant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(type: 'json')]
    private array $branding;

    #[ORM\Column(type: 'json')]
    private array $domains;

    #[ORM\Column(length: 255)]
    private string $mailHostname;

    #[ORM\Column(length: 40)]
    private string $invoicePrefix;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $name,
        array $branding,
        array $domains,
        string $mailHostname,
        string $invoicePrefix,
    ) {
        $this->name = $name;
        $this->branding = $branding;
        $this->domains = $domains;
        $this->mailHostname = $mailHostname;
        $this->invoicePrefix = $invoicePrefix;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
        $this->touch();
    }

    public function getBranding(): array
    {
        return $this->branding;
    }

    public function setBranding(array $branding): void
    {
        $this->branding = $branding;
        $this->touch();
    }

    public function getDomains(): array
    {
        return $this->domains;
    }

    public function setDomains(array $domains): void
    {
        $this->domains = $domains;
        $this->touch();
    }

    public function getMailHostname(): string
    {
        return $this->mailHostname;
    }

    public function setMailHostname(string $mailHostname): void
    {
        $this->mailHostname = $mailHostname;
        $this->touch();
    }

    public function getInvoicePrefix(): string
    {
        return $this->invoicePrefix;
    }

    public function setInvoicePrefix(string $invoicePrefix): void
    {
        $this->invoicePrefix = $invoicePrefix;
        $this->touch();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
