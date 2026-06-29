<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Domain\Entity;

use App\Module\Core\Domain\Entity\User;
use App\Repository\MusicbotPluginLogRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MusicbotPluginLogRepository::class)]
#[ORM\Table(name: 'musicbot_plugin_logs')]
#[ORM\Index(name: 'idx_musicbot_plugin_logs_instance', columns: ['instance_id'])]
#[ORM\Index(name: 'idx_musicbot_plugin_logs_customer', columns: ['customer_id'])]
#[ORM\Index(name: 'idx_musicbot_plugin_logs_plugin', columns: ['plugin_id'])]
class MusicbotPluginLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: MusicbotInstance::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MusicbotInstance $instance;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $customer;

    #[ORM\Column(length: 120)]
    private string $pluginId;

    #[ORM\Column(length: 80)]
    private string $event;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $action;

    #[ORM\Column(length: 30)]
    private string $status;

    #[ORM\Column(type: 'text')]
    private string $message;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $context = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @param array<string, mixed> $context */
    public function __construct(MusicbotInstance $instance, string $pluginId, string $event, ?string $action, string $status, string $message, array $context = [])
    {
        $this->instance = $instance;
        $this->customer = $instance->getCustomer();
        $this->pluginId = $pluginId;
        $this->event = $event;
        $this->action = $action;
        $this->status = $status;
        $this->message = $message;
        $this->context = $context;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getInstance(): MusicbotInstance { return $this->instance; }
    public function getCustomer(): User { return $this->customer; }
    public function getPluginId(): string { return $this->pluginId; }
    public function getEvent(): string { return $this->event; }
    public function getAction(): ?string { return $this->action; }
    public function getStatus(): string { return $this->status; }
    public function getMessage(): string { return $this->message; }
    /** @return array<string, mixed> */ public function getContext(): array { return $this->context; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
