<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Module\Core\Domain\Event\ResourceEventSource;
use App\Module\Core\Domain\Event\ResourceEventSourceTrait;
use App\Module\Core\Domain\Enum\Ts3DatabaseMode;
use App\Module\Core\Domain\Enum\Ts3InstanceStatus;
use App\Repository\Ts3InstanceRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: Ts3InstanceRepository::class)]
#[ORM\Table(name: 'ts3_instances')]
class Ts3Instance implements ResourceEventSource
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

    #[ORM\Column]
    private int $voicePort;

    #[ORM\Column]
    private int $queryPort;

    #[ORM\Column]
    private int $filePort;

    #[ORM\Column(enumType: Ts3DatabaseMode::class)]
    private Ts3DatabaseMode $databaseMode;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $databaseHost = null;

    #[ORM\Column(nullable: true)]
    private ?int $databasePort = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $databaseName = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $databaseUsername = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $databasePassword = null;

    #[ORM\Column(enumType: Ts3InstanceStatus::class)]
    private Ts3InstanceStatus $status;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param array{key_id: string, nonce: string, ciphertext: string}|null $databasePassword
     */
    public function __construct(
        User $customer,
        Agent $node,
        string $name,
        int $voicePort,
        int $queryPort,
        int $filePort,
        Ts3DatabaseMode $databaseMode,
        ?string $databaseHost,
        ?int $databasePort,
        ?string $databaseName,
        ?string $databaseUsername,
        ?array $databasePassword,
        Ts3InstanceStatus $status,
    ) {
        $this->customer = $customer;
        $this->node = $node;
        $this->name = $name;
        $this->voicePort = $voicePort;
        $this->queryPort = $queryPort;
        $this->filePort = $filePort;
        $this->databaseMode = $databaseMode;
        $this->databaseHost = $databaseHost;
        $this->databasePort = $databasePort;
        $this->databaseName = $databaseName;
        $this->databaseUsername = $databaseUsername;
        $this->databasePassword = $databasePassword;
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

    public function getVoicePort(): int
    {
        return $this->voicePort;
    }

    public function setVoicePort(int $voicePort): void
    {
        $this->voicePort = $voicePort;
        $this->touch();
    }

    public function getQueryPort(): int
    {
        return $this->queryPort;
    }

    public function setQueryPort(int $queryPort): void
    {
        $this->queryPort = $queryPort;
        $this->touch();
    }

    public function getFilePort(): int
    {
        return $this->filePort;
    }

    public function setFilePort(int $filePort): void
    {
        $this->filePort = $filePort;
        $this->touch();
    }

    public function getDatabaseMode(): Ts3DatabaseMode
    {
        return $this->databaseMode;
    }

    public function setDatabaseMode(Ts3DatabaseMode $databaseMode): void
    {
        $this->databaseMode = $databaseMode;
        $this->touch();
    }

    public function getDatabaseHost(): ?string
    {
        return $this->databaseHost;
    }

    public function setDatabaseHost(?string $databaseHost): void
    {
        $this->databaseHost = $databaseHost;
        $this->touch();
    }

    public function getDatabasePort(): ?int
    {
        return $this->databasePort;
    }

    public function setDatabasePort(?int $databasePort): void
    {
        $this->databasePort = $databasePort;
        $this->touch();
    }

    public function getDatabaseName(): ?string
    {
        return $this->databaseName;
    }

    public function setDatabaseName(?string $databaseName): void
    {
        $this->databaseName = $databaseName;
        $this->touch();
    }

    public function getDatabaseUsername(): ?string
    {
        return $this->databaseUsername;
    }

    public function setDatabaseUsername(?string $databaseUsername): void
    {
        $this->databaseUsername = $databaseUsername;
        $this->touch();
    }

    /**
     * @return array{key_id: string, nonce: string, ciphertext: string}|null
     */
    public function getDatabasePassword(): ?array
    {
        return $this->databasePassword;
    }

    /**
     * @param array{key_id: string, nonce: string, ciphertext: string}|null $databasePassword
     */
    public function setDatabasePassword(?array $databasePassword): void
    {
        $this->databasePassword = $databasePassword;
        $this->touch();
    }

    public function getStatus(): Ts3InstanceStatus
    {
        return $this->status;
    }

    public function setStatus(Ts3InstanceStatus $status): void
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
