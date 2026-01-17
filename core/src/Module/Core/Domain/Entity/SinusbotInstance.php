<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\SinusbotInstanceRepository;
use App\Module\Core\Application\SecretsCrypto;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SinusbotInstanceRepository::class)]
#[ORM\Table(name: 'sinusbot_instances')]
class SinusbotInstance
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private SinusbotNode $node;

    #[ORM\Column]
    private int $customerId;

    #[ORM\Column(length: 64, unique: true)]
    private string $instanceId;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column]
    private bool $running = false;

    #[ORM\Column]
    private int $webPort;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $publicUrl = null;

    #[ORM\Column(length: 8)]
    private string $connectType;

    #[ORM\Column(length: 255)]
    private string $connectHost;

    #[ORM\Column]
    private int $connectVoicePort;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $connectServerPasswordEncrypted = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $connectPrivilegeKeyEncrypted = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $nickname = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $defaultChannel = null;

    #[ORM\Column(nullable: true)]
    private ?int $volume = null;

    #[ORM\Column]
    private bool $autostart = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    public function __construct(
        SinusbotNode $node,
        int $customerId,
        string $instanceId,
        string $name,
        int $webPort,
        string $connectType,
        string $connectHost,
        int $connectVoicePort,
    ) {
        $this->node = $node;
        $this->customerId = $customerId;
        $this->instanceId = $instanceId;
        $this->name = $name;
        $this->webPort = $webPort;
        $this->connectType = $connectType;
        $this->connectHost = $connectHost;
        $this->connectVoicePort = $connectVoicePort;
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

    public function getCustomerId(): int
    {
        return $this->customerId;
    }

    public function setCustomerId(int $customerId): void
    {
        $this->customerId = $customerId;
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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
        $this->touch();
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function setRunning(bool $running): void
    {
        $this->running = $running;
        $this->touch();
    }

    public function getWebPort(): int
    {
        return $this->webPort;
    }

    public function setWebPort(int $webPort): void
    {
        $this->webPort = $webPort;
        $this->touch();
    }

    public function getPublicUrl(): ?string
    {
        return $this->publicUrl;
    }

    public function setPublicUrl(?string $publicUrl): void
    {
        $this->publicUrl = $publicUrl !== '' ? $publicUrl : null;
        $this->touch();
    }

    public function getConnectType(): string
    {
        return $this->connectType;
    }

    public function setConnectType(string $connectType): void
    {
        $this->connectType = $connectType;
        $this->touch();
    }

    public function getConnectHost(): string
    {
        return $this->connectHost;
    }

    public function setConnectHost(string $connectHost): void
    {
        $this->connectHost = $connectHost;
        $this->touch();
    }

    public function getConnectVoicePort(): int
    {
        return $this->connectVoicePort;
    }

    public function setConnectVoicePort(int $connectVoicePort): void
    {
        $this->connectVoicePort = $connectVoicePort;
        $this->touch();
    }

    public function setConnectServerPassword(?string $password, SecretsCrypto $crypto): void
    {
        $this->connectServerPasswordEncrypted = $password !== null ? $crypto->encrypt($password) : null;
        $this->touch();
    }

    public function getConnectServerPassword(?SecretsCrypto $crypto): ?string
    {
        if ($crypto === null || $this->connectServerPasswordEncrypted === null) {
            return null;
        }

        return $crypto->decrypt($this->connectServerPasswordEncrypted);
    }

    public function setConnectPrivilegeKey(?string $key, SecretsCrypto $crypto): void
    {
        $this->connectPrivilegeKeyEncrypted = $key !== null ? $crypto->encrypt($key) : null;
        $this->touch();
    }

    public function getConnectPrivilegeKey(?SecretsCrypto $crypto): ?string
    {
        if ($crypto === null || $this->connectPrivilegeKeyEncrypted === null) {
            return null;
        }

        return $crypto->decrypt($this->connectPrivilegeKeyEncrypted);
    }

    public function getNickname(): ?string
    {
        return $this->nickname;
    }

    public function setNickname(?string $nickname): void
    {
        $this->nickname = $nickname !== '' ? $nickname : null;
        $this->touch();
    }

    public function getDefaultChannel(): ?string
    {
        return $this->defaultChannel;
    }

    public function setDefaultChannel(?string $defaultChannel): void
    {
        $this->defaultChannel = $defaultChannel !== '' ? $defaultChannel : null;
        $this->touch();
    }

    public function getVolume(): ?int
    {
        return $this->volume;
    }

    public function setVolume(?int $volume): void
    {
        $this->volume = $volume;
        $this->touch();
    }

    public function isAutostart(): bool
    {
        return $this->autostart;
    }

    public function setAutostart(bool $autostart): void
    {
        $this->autostart = $autostart;
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
