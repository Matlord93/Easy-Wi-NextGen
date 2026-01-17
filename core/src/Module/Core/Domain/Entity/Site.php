<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\SiteRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SiteRepository::class)]
#[ORM\Table(name: 'sites')]
#[ORM\Index(name: 'idx_sites_host', columns: ['host'])]
class Site
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 160)]
    private string $name;

    #[ORM\Column(length: 160, unique: true)]
    private string $host;

    #[ORM\Column]
    private bool $allowPrivateNetworkTargets = false;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $cmsTemplateKey = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $name, string $host, bool $allowPrivateNetworkTargets = false)
    {
        $this->name = $name;
        $this->host = $host;
        $this->allowPrivateNetworkTargets = $allowPrivateNetworkTargets;
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

    public function getHost(): string
    {
        return $this->host;
    }

    public function setHost(string $host): void
    {
        $this->host = $host;
        $this->touch();
    }

    public function allowsPrivateNetworkTargets(): bool
    {
        return $this->allowPrivateNetworkTargets;
    }

    public function setAllowPrivateNetworkTargets(bool $allowPrivateNetworkTargets): void
    {
        $this->allowPrivateNetworkTargets = $allowPrivateNetworkTargets;
        $this->touch();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCmsTemplateKey(): ?string
    {
        return $this->cmsTemplateKey;
    }

    public function setCmsTemplateKey(?string $cmsTemplateKey): void
    {
        $this->cmsTemplateKey = $cmsTemplateKey;
        $this->touch();
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
