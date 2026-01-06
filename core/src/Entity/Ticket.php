<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TicketCategory;
use App\Enum\TicketPriority;
use App\Enum\TicketStatus;
use App\Repository\TicketRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TicketRepository::class)]
#[ORM\Table(name: 'tickets')]
class Ticket
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $customer;

    #[ORM\Column(length: 160)]
    private string $subject;

    #[ORM\Column(enumType: TicketCategory::class)]
    private TicketCategory $category;

    #[ORM\Column(enumType: TicketStatus::class)]
    private TicketStatus $status;

    #[ORM\Column(enumType: TicketPriority::class)]
    private TicketPriority $priority;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column]
    private \DateTimeImmutable $lastMessageAt;

    public function __construct(User $customer, string $subject, TicketCategory $category, TicketPriority $priority)
    {
        $this->customer = $customer;
        $this->subject = $subject;
        $this->category = $category;
        $this->priority = $priority;
        $this->status = TicketStatus::Open;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->lastMessageAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCustomer(): User
    {
        return $this->customer;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function setSubject(string $subject): void
    {
        $this->subject = $subject;
        $this->touch();
    }

    public function getCategory(): TicketCategory
    {
        return $this->category;
    }

    public function setCategory(TicketCategory $category): void
    {
        $this->category = $category;
        $this->touch();
    }

    public function getStatus(): TicketStatus
    {
        return $this->status;
    }

    public function setStatus(TicketStatus $status): void
    {
        $this->status = $status;
        $this->touch();
    }

    public function getPriority(): TicketPriority
    {
        return $this->priority;
    }

    public function setPriority(TicketPriority $priority): void
    {
        $this->priority = $priority;
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

    public function getLastMessageAt(): \DateTimeImmutable
    {
        return $this->lastMessageAt;
    }

    public function noteMessage(): void
    {
        $this->lastMessageAt = new \DateTimeImmutable();
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
