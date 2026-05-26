<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Module\Core\Domain\Enum\Ts6InstanceStatus;
use App\Module\Core\Domain\Event\ResourceEventSource;
use App\Module\Core\Domain\Event\ResourceEventSourceTrait;
use App\Repository\Ts6InstanceRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: Ts6InstanceRepository::class)]
#[ORM\Table(name: 'ts6_instances')]
class Ts6Instance implements ResourceEventSource
{
    use ResourceEventSourceTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $customer;

    #[ORM\ManyToOne(targetEntity: Agent::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Agent $node;

    #[ORM\Column(length: 80)]
    private string $name;

    #[ORM\Column(enumType: Ts6InstanceStatus::class)]
    private Ts6InstanceStatus $status;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $installedVersion = null;
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $availableVersion = null;
    #[ORM\Column(length: 16, options: ['default' => 'stable'])]
    private string $updateChannel = 'stable';
    #[ORM\Column(length: 16, nullable: true)]
    private ?string $platformOs = null;
    #[ORM\Column(length: 16, nullable: true)]
    private ?string $platformArch = null;
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $installPath = null;
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastUpdateCheckAt = null;

    public function __construct(User $customer, Agent $node, string $name, Ts6InstanceStatus $status)
    {
        $this->customer = $customer;
        $this->node = $node;
        $this->name = $name;
        $this->status = $status;
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

    public function getNode(): Agent
    {
        return $this->node;
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

    public function getStatus(): Ts6InstanceStatus
    {
        return $this->status;
    }

    public function setStatus(Ts6InstanceStatus $status): void
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

    public function getInstalledVersion(): ?string { return $this->installedVersion; }
    public function setInstalledVersion(?string $installedVersion): void { $this->installedVersion = $installedVersion; $this->touch(); }
    public function getAvailableVersion(): ?string { return $this->availableVersion; }
    public function setAvailableVersion(?string $availableVersion): void { $this->availableVersion = $availableVersion; $this->touch(); }
    public function getUpdateChannel(): string { return $this->updateChannel; }
    public function setUpdateChannel(string $updateChannel): void { $this->updateChannel = $updateChannel; $this->touch(); }
    public function getPlatformOs(): ?string { return $this->platformOs; }
    public function setPlatformOs(?string $platformOs): void { $this->platformOs = $platformOs; $this->touch(); }
    public function getPlatformArch(): ?string { return $this->platformArch; }
    public function setPlatformArch(?string $platformArch): void { $this->platformArch = $platformArch; $this->touch(); }
    public function getInstallPath(): ?string { return $this->installPath; }
    public function setInstallPath(?string $installPath): void { $this->installPath = $installPath; $this->touch(); }
    public function getLastUpdateCheckAt(): ?\DateTimeImmutable { return $this->lastUpdateCheckAt; }
    public function setLastUpdateCheckAt(?\DateTimeImmutable $lastUpdateCheckAt): void { $this->lastUpdateCheckAt = $lastUpdateCheckAt; $this->touch(); }
}
