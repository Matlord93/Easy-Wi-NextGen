<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\ContactMessageRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContactMessageRepository::class)]
#[ORM\Table(name: 'contact_messages')]
#[ORM\Index(name: 'idx_contact_messages_site_status', columns: ['site_id', 'status'])]
#[ORM\Index(name: 'idx_contact_messages_site_created', columns: ['site_id', 'created_at'])]
#[ORM\Index(name: 'idx_contact_messages_ip_created', columns: ['ip_address', 'created_at'])]
class ContactMessage
{
    public const STATUS_NEW = 'new';
    public const STATUS_READ = 'read';
    public const STATUS_REPLIED = 'replied';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Site::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Site $site;

    #[ORM\Column(length: 140)]
    private string $name;

    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\Column(length: 255)]
    private string $subject;

    #[ORM\Column(type: 'text')]
    private string $message;

    #[ORM\Column(length: 45)]
    private string $ipAddress;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_NEW;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $adminReply = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $repliedAt = null;

    public function __construct(Site $site, string $name, string $email, string $subject, string $message, string $ipAddress)
    {
        $this->site = $site;
        $this->name = trim($name);
        $this->email = strtolower(trim($email));
        $this->subject = trim($subject);
        $this->message = trim($message);
        $this->ipAddress = $ipAddress;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getSite(): Site { return $this->site; }
    public function getName(): string { return $this->name; }
    public function getEmail(): string { return $this->email; }
    public function getSubject(): string { return $this->subject; }
    public function getMessage(): string { return $this->message; }
    public function getIpAddress(): string { return $this->ipAddress; }
    public function getStatus(): string { return $this->status; }
    public function getAdminReply(): ?string { return $this->adminReply; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getRepliedAt(): ?\DateTimeImmutable { return $this->repliedAt; }

    public function markRead(): void
    {
        if ($this->status === self::STATUS_NEW) {
            $this->status = self::STATUS_READ;
        }
    }

    public function reply(string $replyText): void
    {
        $this->adminReply = trim($replyText);
        $this->status = self::STATUS_REPLIED;
        $this->repliedAt = new \DateTimeImmutable();
    }
}
