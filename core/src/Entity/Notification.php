<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\NotificationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\Table(name: 'notifications')]
#[ORM\Index(columns: ['recipient_id', 'created_at'], name: 'notifications_recipient_created_idx')]
#[ORM\UniqueConstraint(name: 'notifications_recipient_event_key_idx', columns: ['recipient_id', 'event_key'])]
class Notification
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $recipient;

    #[ORM\Column(length: 32)]
    private string $category;

    #[ORM\Column(length: 160)]
    private string $title;

    #[ORM\Column(length: 255)]
    private string $body;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $actionUrl;

    #[ORM\Column(length: 120)]
    private string $eventKey;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        User $recipient,
        string $category,
        string $title,
        string $body,
        string $eventKey,
        ?string $actionUrl = null,
    ) {
        $this->recipient = $recipient;
        $this->category = $category;
        $this->title = $title;
        $this->body = $body;
        $this->eventKey = $eventKey;
        $this->actionUrl = $actionUrl;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRecipient(): User
    {
        return $this->recipient;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getActionUrl(): ?string
    {
        return $this->actionUrl;
    }

    public function getEventKey(): string
    {
        return $this->eventKey;
    }

    public function getReadAt(): ?\DateTimeImmutable
    {
        return $this->readAt;
    }

    public function isRead(): bool
    {
        return $this->readAt !== null;
    }

    public function markRead(): void
    {
        $this->readAt = new \DateTimeImmutable();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
