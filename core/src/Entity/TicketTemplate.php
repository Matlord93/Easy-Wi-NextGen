<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TicketCategory;
use App\Enum\TicketPriority;
use App\Repository\TicketTemplateRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TicketTemplateRepository::class)]
#[ORM\Table(name: 'ticket_templates')]
#[ORM\Index(name: 'idx_ticket_templates_admin', columns: ['admin_id'])]
class TicketTemplate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'admin_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $admin;

    #[ORM\Column(length: 120)]
    private string $title;

    #[ORM\Column(length: 160)]
    private string $subject;

    #[ORM\Column(length: 20)]
    private string $category;

    #[ORM\Column(length: 20)]
    private string $priority;

    #[ORM\Column(type: 'text')]
    private string $body;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        User $admin,
        string $title,
        string $subject,
        TicketCategory $category,
        TicketPriority $priority,
        string $body,
    ) {
        $this->admin = $admin;
        $this->title = $title;
        $this->subject = $subject;
        $this->category = $category->value;
        $this->priority = $priority->value;
        $this->body = $body;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAdmin(): User
    {
        return $this->admin;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
        $this->touch();
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
        return TicketCategory::from($this->category);
    }

    public function setCategory(TicketCategory $category): void
    {
        $this->category = $category->value;
        $this->touch();
    }

    public function getPriority(): TicketPriority
    {
        return TicketPriority::from($this->priority);
    }

    public function setPriority(TicketPriority $priority): void
    {
        $this->priority = $priority->value;
        $this->touch();
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function setBody(string $body): void
    {
        $this->body = $body;
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

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
