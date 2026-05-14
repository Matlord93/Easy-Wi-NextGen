<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Module\Core\Domain\Event\ResourceEventSource;
use App\Module\Core\Domain\Event\ResourceEventSourceTrait;
use App\Repository\TicketAttachmentRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TicketAttachmentRepository::class)]
#[ORM\Table(name: 'ticket_attachments')]
class TicketAttachment implements ResourceEventSource
{
    use ResourceEventSourceTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Ticket::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Ticket $ticket;

    #[ORM\ManyToOne(targetEntity: TicketMessage::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private TicketMessage $message;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $uploadedBy;

    #[ORM\Column(length: 255)]
    private string $originalName;

    #[ORM\Column(length: 255)]
    private string $storagePath;

    #[ORM\Column(length: 120)]
    private string $mimeType;

    #[ORM\Column]
    private int $sizeBytes;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Ticket $ticket, TicketMessage $message, User $uploadedBy, string $originalName, string $storagePath, string $mimeType, int $sizeBytes)
    {
        $this->ticket = $ticket;
        $this->message = $message;
        $this->uploadedBy = $uploadedBy;
        $this->originalName = $originalName;
        $this->storagePath = $storagePath;
        $this->mimeType = $mimeType;
        $this->sizeBytes = $sizeBytes;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTicket(): Ticket
    {
        return $this->ticket;
    }

    public function getMessage(): TicketMessage
    {
        return $this->message;
    }

    public function getUploadedBy(): User
    {
        return $this->uploadedBy;
    }

    public function getOriginalName(): string
    {
        return $this->originalName;
    }

    public function getStoragePath(): string
    {
        return $this->storagePath;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getSizeBytes(): int
    {
        return $this->sizeBytes;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
