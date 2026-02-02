<?php

declare(strict_types=1);

namespace App\Module\Unifi\Domain\Entity;

use App\Module\Unifi\Infrastructure\Repository\UnifiPortMappingRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UnifiPortMappingRepository::class)]
#[ORM\Table(name: 'unifi_port_mappings')]
class UnifiPortMapping
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 200)]
    private string $ruleName;

    #[ORM\Column(length: 20)]
    private string $ruleType;

    #[ORM\Column]
    private int $port;

    #[ORM\Column(length: 6)]
    private string $protocol;

    #[ORM\Column(length: 45)]
    private string $targetIp;

    #[ORM\Column]
    private int $targetPort;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $unifiRuleId = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $lastSyncStatus = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $ruleName,
        string $ruleType,
        string $protocol,
        int $port,
        string $targetIp,
        int $targetPort,
    ) {
        $this->ruleName = $ruleName;
        $this->ruleType = $ruleType;
        $this->protocol = $protocol;
        $this->port = $port;
        $this->targetIp = $targetIp;
        $this->targetPort = $targetPort;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRuleName(): string
    {
        return $this->ruleName;
    }

    public function getRuleType(): string
    {
        return $this->ruleType;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function getProtocol(): string
    {
        return $this->protocol;
    }

    public function getTargetIp(): string
    {
        return $this->targetIp;
    }

    public function getTargetPort(): int
    {
        return $this->targetPort;
    }

    public function getUnifiRuleId(): ?string
    {
        return $this->unifiRuleId;
    }

    public function setUnifiRuleId(?string $unifiRuleId): void
    {
        $this->unifiRuleId = $unifiRuleId === '' ? null : $unifiRuleId;
        $this->touch();
    }

    public function getLastSyncStatus(): ?string
    {
        return $this->lastSyncStatus;
    }

    public function setLastSyncStatus(?string $lastSyncStatus): void
    {
        $this->lastSyncStatus = $lastSyncStatus === '' ? null : $lastSyncStatus;
        $this->touch();
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function setLastError(?string $lastError): void
    {
        $this->lastError = $lastError === '' ? null : $lastError;
        $this->touch();
    }

    public function updateRule(string $protocol, int $port, string $targetIp, int $targetPort): void
    {
        $this->protocol = $protocol;
        $this->port = $port;
        $this->targetIp = $targetIp;
        $this->targetPort = $targetPort;
        $this->touch();
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
