<?php

declare(strict_types=1);

namespace App\Module\Unifi\Domain\Entity;

use App\Module\Unifi\Infrastructure\Repository\UnifiManualRuleRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UnifiManualRuleRepository::class)]
#[ORM\Table(name: 'unifi_manual_rules')]
class UnifiManualRule
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 160)]
    private string $name;

    #[ORM\Column(length: 6)]
    private string $protocol;

    #[ORM\Column]
    private int $port;

    #[ORM\Column(length: 45)]
    private string $targetIp;

    #[ORM\Column]
    private int $targetPort;

    #[ORM\Column]
    private bool $enabled = true;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $name,
        string $protocol,
        int $port,
        string $targetIp,
        int $targetPort,
        ?string $description = null,
        bool $enabled = true,
    ) {
        $this->name = $name;
        $this->protocol = $protocol;
        $this->port = $port;
        $this->targetIp = $targetIp;
        $this->targetPort = $targetPort;
        $this->description = $description;
        $this->enabled = $enabled;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getProtocol(): string
    {
        return $this->protocol;
    }

    public function setProtocol(string $protocol): void
    {
        $this->protocol = $protocol;
        $this->touch();
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function setPort(int $port): void
    {
        $this->port = $port;
        $this->touch();
    }

    public function getTargetIp(): string
    {
        return $this->targetIp;
    }

    public function setTargetIp(string $targetIp): void
    {
        $this->targetIp = $targetIp;
        $this->touch();
    }

    public function getTargetPort(): int
    {
        return $this->targetPort;
    }

    public function setTargetPort(int $targetPort): void
    {
        $this->targetPort = $targetPort;
        $this->touch();
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
        $this->touch();
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description === null || trim($description) === '' ? null : $description;
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
