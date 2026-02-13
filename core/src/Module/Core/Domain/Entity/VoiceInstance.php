<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\VoiceInstanceRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: VoiceInstanceRepository::class)]
#[ORM\Table(name: 'voice_instances')]
class VoiceInstance
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $customer;

    #[ORM\ManyToOne(targetEntity: VoiceNode::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private VoiceNode $node;

    #[ORM\Column(length: 64)]
    private string $externalId;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(length: 20)]
    private string $status = 'unknown';

    #[ORM\Column(nullable: true)]
    private ?int $playersOnline = null;

    #[ORM\Column(nullable: true)]
    private ?int $playersMax = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $reason = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $errorCode = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $checkedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $customer, VoiceNode $node, string $externalId, string $name)
    {
        $this->customer = $customer;
        $this->node = $node;
        $this->externalId = $externalId;
        $this->name = $name;
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
    public function getNode(): VoiceNode
    {
        return $this->node;
    }
    public function getExternalId(): string
    {
        return $this->externalId;
    }
    public function getName(): string
    {
        return $this->name;
    }
    public function getStatus(): string
    {
        return $this->status;
    }
    public function getPlayersOnline(): ?int
    {
        return $this->playersOnline;
    }
    public function getPlayersMax(): ?int
    {
        return $this->playersMax;
    }
    public function getReason(): ?string
    {
        return $this->reason;
    }
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }
    public function getCheckedAt(): ?\DateTimeImmutable
    {
        return $this->checkedAt;
    }

    public function updateStatus(string $status, ?int $playersOnline, ?int $playersMax, ?string $reason, ?string $errorCode, ?\DateTimeImmutable $checkedAt = null): void
    {
        $this->status = $status;
        $this->playersOnline = $playersOnline;
        $this->playersMax = $playersMax;
        $this->reason = $reason;
        $this->errorCode = $errorCode;
        $this->checkedAt = $checkedAt ?? new \DateTimeImmutable();
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
