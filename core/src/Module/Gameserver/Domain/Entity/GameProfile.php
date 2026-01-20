<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Domain\Entity;

use App\Module\Gameserver\Domain\Enum\EnforceMode;
use App\Module\Gameserver\Infrastructure\Repository\GameProfileRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GameProfileRepository::class)]
#[ORM\Table(name: 'game_profiles')]
class GameProfile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120, unique: true)]
    private string $gameKey;

    #[ORM\Column(length: 40, enumType: EnforceMode::class)]
    private EnforceMode $enforceModePorts;

    #[ORM\Column(length: 40, enumType: EnforceMode::class)]
    private EnforceMode $enforceModeSlots;

    #[ORM\Column(type: 'json')]
    private array $portRoles = [];

    #[ORM\Column(type: 'json')]
    private array $slotRules = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $gameKey,
        EnforceMode $enforceModePorts,
        EnforceMode $enforceModeSlots,
        array $portRoles,
        array $slotRules,
    ) {
        $this->gameKey = $gameKey;
        $this->enforceModePorts = $enforceModePorts;
        $this->enforceModeSlots = $enforceModeSlots;
        $this->portRoles = $portRoles;
        $this->slotRules = $slotRules;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGameKey(): string
    {
        return $this->gameKey;
    }

    public function getEnforceModePorts(): EnforceMode
    {
        return $this->enforceModePorts;
    }

    public function setEnforceModePorts(EnforceMode $enforceModePorts): void
    {
        $this->enforceModePorts = $enforceModePorts;
        $this->touch();
    }

    public function getEnforceModeSlots(): EnforceMode
    {
        return $this->enforceModeSlots;
    }

    public function setEnforceModeSlots(EnforceMode $enforceModeSlots): void
    {
        $this->enforceModeSlots = $enforceModeSlots;
        $this->touch();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getPortRoles(): array
    {
        return $this->portRoles;
    }

    /**
     * @param array<int, array<string, mixed>> $portRoles
     */
    public function setPortRoles(array $portRoles): void
    {
        $this->portRoles = $portRoles;
        $this->touch();
    }

    /**
     * @return array<string, mixed>
     */
    public function getSlotRules(): array
    {
        return $this->slotRules;
    }

    /**
     * @param array<string, mixed> $slotRules
     */
    public function setSlotRules(array $slotRules): void
    {
        $this->slotRules = $slotRules;
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
