<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Domain\Entity;

use App\Module\Core\Domain\Entity\User;
use App\Repository\MusicbotRuntimeEventRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MusicbotRuntimeEventRepository::class)]
#[ORM\Table(name: 'musicbot_runtime_events')]
#[ORM\Index(name: 'idx_musicbot_runtime_events_instance_created', columns: ['musicbot_instance_id', 'created_at'])]
#[ORM\Index(name: 'idx_musicbot_runtime_events_type', columns: ['type'])]
#[ORM\Index(name: 'idx_musicbot_runtime_events_customer', columns: ['customer_id'])]
class MusicbotRuntimeEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: MusicbotInstance::class)]
    #[ORM\JoinColumn(name: 'musicbot_instance_id', nullable: false, onDelete: 'CASCADE')]
    private MusicbotInstance $musicbotInstance;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'customer_id', nullable: false, onDelete: 'CASCADE')]
    private User $customer;

    #[ORM\Column(length: 80)]
    private string $type;

    #[ORM\Column(length: 16)]
    private string $level;

    #[ORM\Column(length: 255)]
    private string $message;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $context = [];

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    /** @param array<string, mixed> $context */
    public function __construct(MusicbotInstance $musicbotInstance, string $type, string $level = 'info', string $message = '', array $context = [])
    {
        $this->musicbotInstance = $musicbotInstance;
        $this->customer = $musicbotInstance->getCustomer();
        $this->type = $type;
        $this->level = $level;
        $this->message = $message;
        $this->context = $context;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getMusicbotInstance(): MusicbotInstance { return $this->musicbotInstance; }
    public function getCustomer(): User { return $this->customer; }
    public function getType(): string { return $this->type; }
    public function getLevel(): string { return $this->level; }
    public function getMessage(): string { return $this->message; }
    /** @return array<string, mixed> */ public function getContext(): array { return $this->context; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
