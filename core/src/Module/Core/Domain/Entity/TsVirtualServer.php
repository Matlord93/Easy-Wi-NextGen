<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Module\Core\Domain\Event\ResourceEventSource;
use App\Module\Core\Domain\Event\ResourceEventSourceTrait;
use App\Module\Core\Domain\Enum\TsVirtualServerStatus;
use App\Repository\TsVirtualServerRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TsVirtualServerRepository::class)]
#[ORM\Table(name: 'ts_virtual_server')]
class TsVirtualServer implements ResourceEventSource
{
    use ResourceEventSourceTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Ts6Instance::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Ts6Instance $instance;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $customer;

    #[ORM\Column(length: 80)]
    private string $name;

    #[ORM\Column]
    private int $slots;

    #[ORM\Column(enumType: TsVirtualServerStatus::class)]
    private TsVirtualServerStatus $status;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Ts6Instance $instance,
        User $customer,
        string $name,
        int $slots,
        TsVirtualServerStatus $status,
    ) {
        $this->instance = $instance;
        $this->customer = $customer;
        $this->name = $name;
        $this->slots = $slots;
        $this->status = $status;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getInstance(): Ts6Instance
    {
        return $this->instance;
    }

    public function getCustomer(): User
    {
        return $this->customer;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
        $this->touch();
    }

    public function getSlots(): int
    {
        return $this->slots;
    }

    public function setSlots(int $slots): void
    {
        $this->slots = max(0, $slots);
        $this->touch();
    }

    public function getStatus(): TsVirtualServerStatus
    {
        return $this->status;
    }

    public function setStatus(TsVirtualServerStatus $status): void
    {
        $this->status = $status;
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
