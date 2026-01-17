<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\Ts6TokenRepository;
use App\Module\Core\Application\SecretsCrypto;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: Ts6TokenRepository::class)]
#[ORM\Table(name: 'ts6_tokens')]
class Ts6Token
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Ts6VirtualServer::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Ts6VirtualServer $virtualServer;

    #[ORM\Column(type: 'text')]
    private string $tokenEncrypted;

    #[ORM\Column(length: 16)]
    private string $type;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct(Ts6VirtualServer $virtualServer, string $tokenEncrypted, string $type)
    {
        $this->virtualServer = $virtualServer;
        $this->tokenEncrypted = $tokenEncrypted;
        $this->type = $type;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVirtualServer(): Ts6VirtualServer
    {
        return $this->virtualServer;
    }

    public function getTokenEncrypted(): string
    {
        return $this->tokenEncrypted;
    }

    public function setToken(string $token, SecretsCrypto $crypto): void
    {
        $this->tokenEncrypted = $crypto->encrypt($token);
    }

    public function getToken(SecretsCrypto $crypto): string
    {
        return $crypto->decrypt($this->tokenEncrypted);
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): void
    {
        $this->type = $type;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function deactivate(): void
    {
        if (!$this->active) {
            return;
        }

        $this->active = false;
        $this->revokedAt = new \DateTimeImmutable();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }
}
