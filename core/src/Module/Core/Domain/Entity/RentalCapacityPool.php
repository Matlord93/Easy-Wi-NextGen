<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/** @psalm-api Operator-assigned sellable inventory, excluding capacity committed outside the shop. */
#[ORM\Entity, ORM\Table(name: 'rental_capacity_pools')]
class RentalCapacityPool
{
    /** @var array<string,int> */
    #[ORM\Column(type: 'json')] private array $limits = [];
    #[ORM\Column] private bool $enabled = true;
    /** @param array<string,int> $limits */
    public function __construct(#[ORM\Id, ORM\Column(length: 100)] private string $id, array $limits)
    {
        $this->configure($limits, true);
    }

    /** @param array<string,int> $limits */
    public function configure(array $limits, bool $enabled): void
    {
        $validated = [];
        foreach (['cpu', 'ram_mib', 'disk_mib', 'ports', 'ipv4', 'slots', 'systems'] as $key) {
            $value = $limits[$key] ?? 0;
            if ($value < 0 || $value > 2_147_483_647) {
                throw new \InvalidArgumentException('Invalid capacity limit.');
            }
            $validated[$key] = $value;
        }
        $this->limits = $validated;
        $this->enabled = $enabled;
    }

    public function getId(): string
    {
        return $this->id;
    }

    /** @return array<string,int> */
    public function getLimits(): array
    {
        return $this->limits;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
