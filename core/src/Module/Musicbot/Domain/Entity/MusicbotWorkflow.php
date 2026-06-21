<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Domain\Entity;

use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Enum\MusicbotWorkflowTriggerType;
use App\Repository\MusicbotWorkflowRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MusicbotWorkflowRepository::class)]
#[ORM\Table(name: 'musicbot_workflows')]
#[ORM\Index(name: 'idx_musicbot_workflows_customer', columns: ['customer_id'])]
#[ORM\Index(name: 'idx_musicbot_workflows_instance', columns: ['instance_id'])]
#[ORM\Index(name: 'idx_musicbot_workflows_enabled', columns: ['enabled', 'trigger_type'])]
class MusicbotWorkflow
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $customer;

    #[ORM\ManyToOne(targetEntity: MusicbotInstance::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MusicbotInstance $instance;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(enumType: MusicbotWorkflowTriggerType::class)]
    private MusicbotWorkflowTriggerType $triggerType;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $triggerConfig = [];

    #[ORM\Column]
    private bool $enabled = true;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastTriggeredAt = null;

    #[ORM\Column]
    private int $executionCount = 0;

    /** @var Collection<int, MusicbotWorkflowCondition> */
    #[ORM\OneToMany(
        targetEntity: MusicbotWorkflowCondition::class,
        mappedBy: 'workflow',
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    #[ORM\OrderBy(['sortOrder' => 'ASC'])]
    private Collection $conditions;

    /** @var Collection<int, MusicbotWorkflowAction> */
    #[ORM\OneToMany(
        targetEntity: MusicbotWorkflowAction::class,
        mappedBy: 'workflow',
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    #[ORM\OrderBy(['sortOrder' => 'ASC'])]
    private Collection $actions;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param array<string, mixed> $triggerConfig
     */
    public function __construct(
        User $customer,
        MusicbotInstance $instance,
        string $name,
        MusicbotWorkflowTriggerType $triggerType,
        array $triggerConfig = [],
        ?string $description = null,
        bool $enabled = true,
    ) {
        $this->customer = $customer;
        $this->instance = $instance;
        $this->name = $name;
        $this->triggerType = $triggerType;
        $this->triggerConfig = $triggerConfig;
        $this->description = $description;
        $this->enabled = $enabled;
        $this->conditions = new ArrayCollection();
        $this->actions = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getCustomer(): User { return $this->customer; }
    public function getInstance(): MusicbotInstance { return $this->instance; }
    public function getName(): string { return $this->name; }
    public function getDescription(): ?string { return $this->description; }
    public function getTriggerType(): MusicbotWorkflowTriggerType { return $this->triggerType; }
    /** @return array<string, mixed> */ public function getTriggerConfig(): array { return $this->triggerConfig; }
    public function isEnabled(): bool { return $this->enabled; }
    public function getLastTriggeredAt(): ?\DateTimeImmutable { return $this->lastTriggeredAt; }
    public function getExecutionCount(): int { return $this->executionCount; }
    /** @return Collection<int, MusicbotWorkflowCondition> */ public function getConditions(): Collection { return $this->conditions; }
    /** @return Collection<int, MusicbotWorkflowAction> */ public function getActions(): Collection { return $this->actions; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /**
     * @param array<string, mixed> $triggerConfig
     */
    public function update(
        string $name,
        ?string $description,
        MusicbotWorkflowTriggerType $triggerType,
        array $triggerConfig,
        bool $enabled,
    ): void {
        $this->name = $name;
        $this->description = $description;
        $this->triggerType = $triggerType;
        $this->triggerConfig = $triggerConfig;
        $this->enabled = $enabled;
        $this->touch();
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
        $this->touch();
    }

    public function markTriggered(): void
    {
        $this->lastTriggeredAt = new \DateTimeImmutable();
        ++$this->executionCount;
        $this->touch();
    }

    public function clearConditions(): void
    {
        $this->conditions->clear();
    }

    public function addCondition(MusicbotWorkflowCondition $condition): void
    {
        $this->conditions->add($condition);
    }

    public function clearActions(): void
    {
        $this->actions->clear();
    }

    public function addAction(MusicbotWorkflowAction $action): void
    {
        $this->actions->add($action);
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
