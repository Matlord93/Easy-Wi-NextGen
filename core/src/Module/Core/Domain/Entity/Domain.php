<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Module\Core\Domain\Entity\Concerns\TestSettableIdTrait;
use App\Module\Core\Domain\Event\ResourceEventSource;
use App\Module\Core\Domain\Event\ResourceEventSourceTrait;
use App\Repository\DomainRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DomainRepository::class)]
#[ORM\Table(name: 'domains')]
class Domain implements ResourceEventSource
{
    use ResourceEventSourceTrait;
    use TestSettableIdTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $customer;

    #[ORM\ManyToOne(targetEntity: Webspace::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Webspace $webspace;

    #[ORM\Column(name: 'capability_webspace', options: ['default' => true])]
    private bool $hasWebspaceCapability = true;

    #[ORM\Column(name: 'capability_mail', options: ['default' => false])]
    private bool $hasMailCapability = false;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 32)]
    private string $status;

    #[ORM\Column(length: 20, options: ['default' => 'domain'])]
    private string $type = 'domain';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $targetPath = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $redirectHttps = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $redirectWww = false;

    #[ORM\Column(length: 20, options: ['default' => 'pending'])]
    private string $applyStatus = 'pending';

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $lastErrorCode = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastErrorMessage = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastAppliedAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $serverAliases = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $sslExpiresAt = null;

    public function __construct(User $customer, ?Webspace $webspace, string $name, string $status = 'pending')
    {
        $this->customer = $customer;
        $this->webspace = $webspace;
        $this->hasWebspaceCapability = $webspace !== null;
        $this->name = $name;
        $this->status = $status;
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

    public function getWebspace(): ?Webspace
    {
        return $this->webspace;
    }

    public function hasWebspaceCapability(): bool
    {
        return $this->hasWebspaceCapability;
    }

    public function hasMailCapability(): bool
    {
        return $this->hasMailCapability;
    }

    public function setCapabilities(bool $webspace, bool $mail): void
    {
        $this->hasWebspaceCapability = $webspace;
        $this->hasMailCapability = $mail;
        if (!$webspace) {
            $this->webspace = null;
        }
        $this->touch();
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

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
        $this->touch();
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): void
    {
        $this->type = $type;
        $this->touch();
    }

    public function getTargetPath(): ?string
    {
        return $this->targetPath;
    }

    public function setTargetPath(?string $targetPath): void
    {
        $this->targetPath = $targetPath;
        $this->touch();
    }

    public function isRedirectHttps(): bool
    {
        return $this->redirectHttps;
    }

    public function setRedirectHttps(bool $redirectHttps): void
    {
        $this->redirectHttps = $redirectHttps;
        $this->touch();
    }

    public function isRedirectWww(): bool
    {
        return $this->redirectWww;
    }

    public function setRedirectWww(bool $redirectWww): void
    {
        $this->redirectWww = $redirectWww;
        $this->touch();
    }

    public function getApplyStatus(): string
    {
        return $this->applyStatus;
    }

    public function setApplyStatus(string $applyStatus): void
    {
        $this->applyStatus = $applyStatus;
        $this->touch();
    }

    public function setApplyError(?string $code, ?string $message): void
    {
        $this->lastErrorCode = $code;
        $this->lastErrorMessage = $message;
        $this->touch();
    }

    public function getLastErrorCode(): ?string
    {
        return $this->lastErrorCode;
    }

    public function getLastErrorMessage(): ?string
    {
        return $this->lastErrorMessage;
    }

    public function getLastAppliedAt(): ?\DateTimeImmutable
    {
        return $this->lastAppliedAt;
    }

    public function markApplied(): void
    {
        $this->applyStatus = 'succeeded';
        $this->lastAppliedAt = new \DateTimeImmutable();
        $this->lastErrorCode = null;
        $this->lastErrorMessage = null;
        $this->touch();
    }

    /**
     * @return string[]
     */
    public function getServerAliases(): array
    {
        return $this->parseServerAliases($this->serverAliases);
    }

    public function getServerAliasesRaw(): ?string
    {
        return $this->serverAliases;
    }

    /**
     * @param string[] $serverAliases
     */
    public function setServerAliases(array $serverAliases): void
    {
        $aliases = [];
        foreach ($serverAliases as $alias) {
            $alias = strtolower(trim($alias));
            if ($alias === '') {
                continue;
            }
            $aliases[] = $alias;
        }

        $aliases = array_values(array_unique($aliases));
        $this->serverAliases = $aliases === [] ? null : implode(', ', $aliases);
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

    public function getSslExpiresAt(): ?\DateTimeImmutable
    {
        return $this->sslExpiresAt;
    }

    public function setSslExpiresAt(?\DateTimeImmutable $sslExpiresAt): void
    {
        $this->sslExpiresAt = $sslExpiresAt;
        $this->touch();
    }

    /**
     * @return string[]
     */
    private function parseServerAliases(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $parts = preg_split('/[\s,;]+/', $raw) ?: [];
        $aliases = [];
        foreach ($parts as $part) {
            $part = strtolower(trim((string) $part));
            if ($part === '') {
                continue;
            }
            $aliases[] = $part;
        }

        return array_values(array_unique($aliases));
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
