<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\Ts3VirtualServerRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: Ts3VirtualServerRepository::class)]
#[ORM\Table(name: 'ts3_virtual_servers')]
class Ts3VirtualServer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Ts3Node::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Ts3Node $node;

    #[ORM\Column]
    private int $customerId;

    #[ORM\Column]
    private int $sid;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(nullable: true)]
    private ?int $voicePort = null;

    #[ORM\Column(nullable: true)]
    private ?int $filetransferPort = null;

    #[ORM\Column(length: 32)]
    private string $status = 'unknown';

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    public function __construct(Ts3Node $node, int $customerId, int $sid, string $name)
    {
        $this->node = $node;
        $this->customerId = $customerId;
        $this->sid = $sid;
        $this->name = $name;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNode(): Ts3Node
    {
        return $this->node;
    }

    public function getCustomerId(): int
    {
        return $this->customerId;
    }

    public function setCustomerId(int $customerId): void
    {
        $this->customerId = $customerId;
        $this->touch();
    }

    public function getSid(): int
    {
        return $this->sid;
    }

    public function setSid(int $sid): void
    {
        $this->sid = $sid;
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

    public function getVoicePort(): ?int
    {
        return $this->voicePort;
    }

    public function setVoicePort(?int $voicePort): void
    {
        $this->voicePort = $voicePort !== null ? max(1, $voicePort) : null;
        $this->touch();
    }

    public function getFiletransferPort(): ?int
    {
        return $this->filetransferPort;
    }

    public function setFiletransferPort(?int $filetransferPort): void
    {
        $this->filetransferPort = $filetransferPort !== null ? max(1, $filetransferPort) : null;
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getArchivedAt(): ?\DateTimeImmutable
    {
        return $this->archivedAt;
    }

    public function archive(): void
    {
        $this->archivedAt = new \DateTimeImmutable();
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
