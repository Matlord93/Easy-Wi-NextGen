<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\Event\ResourceEventSource;
use App\Domain\Event\ResourceEventSourceTrait;
use App\Enum\InstanceScheduleAction;
use App\Repository\InstanceScheduleRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InstanceScheduleRepository::class)]
#[ORM\Table(name: 'instance_schedules')]
class InstanceSchedule implements ResourceEventSource
{
    use ResourceEventSourceTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Instance::class, inversedBy: 'schedules')]
    #[ORM\JoinColumn(nullable: false)]
    private Instance $instance;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $customer;

    #[ORM\Column(enumType: InstanceScheduleAction::class)]
    private InstanceScheduleAction $action;

    #[ORM\Column(length: 120)]
    private string $cronExpression;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $timeZone;

    #[ORM\Column]
    private bool $enabled;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Instance $instance,
        User $customer,
        InstanceScheduleAction $action,
        string $cronExpression,
        ?string $timeZone,
        bool $enabled,
    ) {
        $this->instance = $instance;
        $this->customer = $customer;
        $this->action = $action;
        $this->cronExpression = $cronExpression;
        $this->timeZone = $timeZone;
        $this->enabled = $enabled;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getInstance(): Instance
    {
        return $this->instance;
    }

    public function getCustomer(): User
    {
        return $this->customer;
    }

    public function getAction(): InstanceScheduleAction
    {
        return $this->action;
    }

    public function getCronExpression(): string
    {
        return $this->cronExpression;
    }

    public function getTimeZone(): ?string
    {
        return $this->timeZone;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function update(
        InstanceScheduleAction $action,
        string $cronExpression,
        ?string $timeZone,
        bool $enabled,
    ): void {
        $this->action = $action;
        $this->cronExpression = $cronExpression;
        $this->timeZone = $timeZone;
        $this->enabled = $enabled;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
