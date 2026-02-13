<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\WebspaceNodeRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WebspaceNodeRepository::class)]
#[ORM\Table(name: 'webspace_nodes')]
class WebspaceNode
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(length: 255)]
    private string $host;

    #[ORM\Column(options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\Column(length: 20)]
    private string $webserverType = 'nginx';

    #[ORM\Column(length: 255)]
    private string $basePath;

    #[ORM\Column(type: 'json')]
    private array $vhostPaths = [];

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $phpFpmMode = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $defaultTemplates = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $tlsDefaults = null;

    #[ORM\ManyToOne(targetEntity: Agent::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Agent $agent;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $name, string $host, string $basePath, Agent $agent)
    {
        $this->name = $name;
        $this->host = $host;
        $this->basePath = $basePath;
        $this->agent = $agent;
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
    public function isEnabled(): bool
    {
        return $this->enabled;
    }
    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
        $this->touch();
    }
    public function getWebserverType(): string
    {
        return $this->webserverType;
    }
    public function setWebserverType(string $webserverType): void
    {
        $this->webserverType = $webserverType;
        $this->touch();
    }
    public function getBasePath(): string
    {
        return $this->basePath;
    }
    public function setBasePath(string $basePath): void
    {
        $this->basePath = $basePath;
        $this->touch();
    }
    public function getVhostPaths(): array
    {
        return $this->vhostPaths;
    }
    public function setVhostPaths(array $vhostPaths): void
    {
        $this->vhostPaths = $vhostPaths;
        $this->touch();
    }
    public function getPhpFpmMode(): ?string
    {
        return $this->phpFpmMode;
    }
    public function setPhpFpmMode(?string $phpFpmMode): void
    {
        $this->phpFpmMode = $phpFpmMode;
        $this->touch();
    }
    public function getDefaultTemplates(): ?array
    {
        return $this->defaultTemplates;
    }
    public function setDefaultTemplates(?array $defaultTemplates): void
    {
        $this->defaultTemplates = $defaultTemplates;
        $this->touch();
    }
    public function getTlsDefaults(): ?array
    {
        return $this->tlsDefaults;
    }
    public function setTlsDefaults(?array $tlsDefaults): void
    {
        $this->tlsDefaults = $tlsDefaults;
        $this->touch();
    }
    public function getAgent(): Agent
    {
        return $this->agent;
    }
    public function setAgent(Agent $agent): void
    {
        $this->agent = $agent;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
