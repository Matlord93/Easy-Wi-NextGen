<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Module\Core\Application\SecretsCrypto;
use App\Repository\SinusbotInstanceRepository;
use App\Module\Core\Domain\Entity\User;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SinusbotInstanceRepository::class)]
#[ORM\Table(name: 'sinusbot_instances')]
#[ORM\UniqueConstraint(name: 'uniq_sinusbot_instance_customer', columns: ['customer_id'])]
class SinusbotInstance
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private SinusbotNode $node;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $customer = null;

    #[ORM\Column(length: 64, unique: true)]
    private string $instanceId;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $manageUrl = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $botId = null;

    #[ORM\Column(nullable: true)]
    private ?int $webPort = null;

    #[ORM\Column(length: 120)]
    private string $sinusbotUsername;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $sinusbotPasswordEncrypted = null;

    #[ORM\Column]
    private int $botQuota;

    #[ORM\Column(length: 16)]
    private string $status;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastSeenAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    public function __construct(
        SinusbotNode $node,
        ?User $customer,
        string $instanceId,
        string $sinusbotUsername,
        int $botQuota,
        string $status,
    ) {
        $this->node = $node;
        $this->customer = $customer;
        $this->instanceId = $instanceId;
        $this->sinusbotUsername = $sinusbotUsername;
        $this->botQuota = $botQuota;
        $this->status = $status;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNode(): SinusbotNode
    {
        return $this->node;
    }

    public function setNode(SinusbotNode $node): void
    {
        $this->node = $node;
        $this->touch();
    }

    public function getCustomer(): ?User
    {
        return $this->customer;
    }

    public function setCustomer(?User $customer): void
    {
        $this->customer = $customer;
        $this->touch();
    }

    public function getInstanceId(): string
    {
        return $this->instanceId;
    }

    public function setInstanceId(string $instanceId): void
    {
        $this->instanceId = $instanceId;
        $this->touch();
    }

    public function getManageUrl(): ?string
    {
        return $this->manageUrl;
    }

    public function setManageUrl(?string $manageUrl): void
    {
        $this->manageUrl = $manageUrl !== '' ? $manageUrl : null;
        $this->touch();
    }

    public function getBotId(): ?string
    {
        return $this->botId;
    }

    public function setBotId(?string $botId): void
    {
        $this->botId = $botId !== '' ? $botId : null;
        $this->touch();
    }

    public function getWebPort(): ?int
    {
        return $this->webPort;
    }

    public function setWebPort(?int $webPort): void
    {
        $this->webPort = $webPort;
        $this->touch();
    }

    public function getSinusbotUsername(): string
    {
        return $this->sinusbotUsername;
    }

    public function setSinusbotUsername(string $sinusbotUsername): void
    {
        $this->sinusbotUsername = $sinusbotUsername;
        $this->touch();
    }

    public function setSinusbotPassword(?string $password, SecretsCrypto $crypto): void
    {
        $this->sinusbotPasswordEncrypted = $password !== null ? $crypto->encrypt($password) : null;
        $this->touch();
    }

    public function getSinusbotPassword(?SecretsCrypto $crypto): ?string
    {
        if ($crypto === null || $this->sinusbotPasswordEncrypted === null) {
            return null;
        }

        return $crypto->decrypt($this->sinusbotPasswordEncrypted);
    }

    public function getBotQuota(): int
    {
        return $this->botQuota;
    }

    public function setBotQuota(int $botQuota): void
    {
        $this->botQuota = $botQuota;
        $this->touch();
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
        $this->touch();
    }

    public function getLastSeenAt(): ?\DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function setLastSeenAt(?\DateTimeImmutable $lastSeenAt): void
    {
        $this->lastSeenAt = $lastSeenAt;
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

    public function getArchivedAt(): ?\DateTimeImmutable
    {
        return $this->archivedAt;
    }

    public function setArchivedAt(?\DateTimeImmutable $archivedAt): void
    {
        $this->archivedAt = $archivedAt;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
