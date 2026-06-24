<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Domain\Entity;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Enum\MusicbotInstanceStatus;
use App\Repository\MusicbotInstanceRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MusicbotInstanceRepository::class)]
#[ORM\Table(name: 'musicbot_instances')]
#[ORM\Index(name: 'idx_musicbot_instances_customer', columns: ['customer_id'])]
#[ORM\Index(name: 'idx_musicbot_instances_node', columns: ['node_id'])]
class MusicbotInstance
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $customer;

    #[ORM\ManyToOne(targetEntity: Agent::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Agent $node;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(enumType: MusicbotInstanceStatus::class)]
    private MusicbotInstanceStatus $status = MusicbotInstanceStatus::Provisioning;

    #[ORM\Column(length: 120)]
    private string $serviceName;

    #[ORM\Column(length: 255)]
    private string $installPath;

    #[ORM\Column]
    private int $cpuLimit;

    #[ORM\Column]
    private int $ramLimit;

    #[ORM\Column]
    private int $diskLimit;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastError = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json', options: ['default' => '{}'])]
    private array $instanceConfig = [];

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $runtimePayload = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $customer, Agent $node, string $name, string $serviceName, string $installPath, int $cpuLimit = 0, int $ramLimit = 0, int $diskLimit = 0)
    {
        $this->customer = $customer;
        $this->node = $node;
        $this->name = $name;
        $this->serviceName = $serviceName;
        $this->installPath = $installPath;
        $this->cpuLimit = max(0, $cpuLimit);
        $this->ramLimit = max(0, $ramLimit);
        $this->diskLimit = max(0, $diskLimit);
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int { return $this->id; }
    /** @return array<string, mixed> */ public function getInstanceConfig(): array { return $this->instanceConfig; }
    /** @param array<string, mixed> $config */ public function setInstanceConfig(array $config): void { $this->instanceConfig = $config; $this->touch(); }
    public function getConfigValue(string $key, mixed $default = null): mixed { return $this->instanceConfig[$key] ?? $default; }
    public function getCustomer(): User { return $this->customer; }
    public function getNode(): Agent { return $this->node; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; $this->touch(); }
    public function getStatus(): MusicbotInstanceStatus { return $this->status; }
    public function setStatus(MusicbotInstanceStatus $status): void { $this->status = $status; $this->touch(); }
    public function getServiceName(): string { return $this->serviceName; }
    public function setServiceName(string $serviceName): void { $this->serviceName = $serviceName; $this->touch(); }
    public function getInstallPath(): string { return $this->installPath; }
    public function setInstallPath(string $installPath): void { $this->installPath = $installPath; $this->touch(); }
    public function getCpuLimit(): int { return $this->cpuLimit; }
    public function setCpuLimit(int $cpuLimit): void { $this->cpuLimit = max(0, $cpuLimit); $this->touch(); }
    public function getRamLimit(): int { return $this->ramLimit; }
    public function setRamLimit(int $ramLimit): void { $this->ramLimit = max(0, $ramLimit); $this->touch(); }
    public function getDiskLimit(): int { return $this->diskLimit; }
    public function setDiskLimit(int $diskLimit): void { $this->diskLimit = max(0, $diskLimit); $this->touch(); }
    public function getLastError(): ?string { return $this->lastError; }
    public function setLastError(?string $lastError): void { $this->lastError = $lastError; $this->touch(); }
    /** @return array<string, mixed>|null */ public function getRuntimePayload(): ?array { return $this->runtimePayload; }
    /** @param array<string, mixed>|null $runtimePayload */ public function setRuntimePayload(?array $runtimePayload): void { $this->runtimePayload = $runtimePayload; $this->touch(); }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
