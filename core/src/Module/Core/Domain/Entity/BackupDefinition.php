<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Module\Core\Domain\Event\ResourceEventSource;
use App\Module\Core\Domain\Event\ResourceEventSourceTrait;
use App\Module\Core\Domain\Enum\BackupTargetType;
use App\Repository\BackupDefinitionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BackupDefinitionRepository::class)]
#[ORM\Table(name: 'backup_definitions')]
class BackupDefinition implements ResourceEventSource
{
    use ResourceEventSourceTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $customer;

    #[ORM\Column(enumType: BackupTargetType::class)]
    private BackupTargetType $targetType;

    #[ORM\Column(length: 64)]
    private string $targetId;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $label;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\OneToOne(mappedBy: 'definition', targetEntity: BackupSchedule::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private ?BackupSchedule $schedule = null;

    public function __construct(User $customer, BackupTargetType $targetType, string $targetId, ?string $label)
    {
        $this->customer = $customer;
        $this->targetType = $targetType;
        $this->targetId = $targetId;
        $this->label = $label;
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

    public function getTargetType(): BackupTargetType
    {
        return $this->targetType;
    }

    public function getTargetId(): string
    {
        return $this->targetId;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function getSchedule(): ?BackupSchedule
    {
        return $this->schedule;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setLabel(?string $label): void
    {
        $this->label = $label;
        $this->touch();
    }

    public function setSchedule(?BackupSchedule $schedule): void
    {
        $this->schedule = $schedule;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
