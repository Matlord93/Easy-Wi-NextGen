<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/** @psalm-api Held until confirmed resource cleanup, including retained data. */
#[ORM\Entity, ORM\Table(name: 'rental_capacity_leases')]
#[ORM\Index(name: 'idx_capacity_held', columns: ['pool_id', 'released_at'])]
class RentalCapacityLease
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $releasedAt = null;
    #[ORM\Column] private int $cpu = 0;
    #[ORM\Column] private int $ramMib = 0;
    #[ORM\Column] private int $diskMib = 0;
    #[ORM\Column] private int $ports = 0;
    #[ORM\Column] private int $ipv4 = 0;
    #[ORM\Column] private int $slots = 0;
    #[ORM\Column] private int $systems = 0;
    /** @param array<string,int> $resources */
    public function __construct(
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false)] private RentalCapacityPool $pool,
        #[ORM\OneToOne, ORM\JoinColumn(nullable: false)] private ShopContract $contract,
        #[ORM\Column(type: 'json')] private array $resources,
    ) {
        $this->cpu = $resources['cpu'];
        $this->ramMib = $resources['ram_mib'];
        $this->diskMib = $resources['disk_mib'];
        $this->ports = $resources['ports'];
        $this->ipv4 = $resources['ipv4'];
        $this->slots = $resources['slots'];
        $this->systems = $resources['systems'];
    }

    public function getPool(): RentalCapacityPool
    {
        return $this->pool;
    }

    /** @return array<string,int> */
    public function getResources(): array
    {
        return $this->resources;
    }

    public function release(\DateTimeImmutable $at): void
    {
        $this->releasedAt ??= $at;
    }
}
