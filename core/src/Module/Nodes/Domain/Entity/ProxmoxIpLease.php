<?php

declare(strict_types=1);

namespace App\Module\Nodes\Domain\Entity;

use App\Module\Nodes\Domain\Proxmox\ProxmoxIpRange;
use Doctrine\ORM\Mapping as ORM;

/** @psalm-api Doctrine entity and Twig view. */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'proxmox_ip_leases')]
#[ORM\UniqueConstraint(name: 'uniq_pve_ip_address', columns: ['family', 'address_key'])]
#[ORM\UniqueConstraint(name: 'uniq_pve_ip_lease_task_slot', columns: ['task_id', 'family', 'reservation_slot'])]
class ProxmoxIpLease
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false)] private ProxmoxTask $task,
        #[ORM\Column] private int $family,
        #[ORM\Column(length: 32)] private string $addressKey,
        #[ORM\Column] private int $prefix,
        #[ORM\Column(length: 45)] private string $gateway,
        #[ORM\Column(length: 32)] private string $bridge,
        #[ORM\Column(nullable: true)] private ?int $vlan,
        #[ORM\Column(options: ['default' => 0])] private int $reservationSlot = 0,
    ) {
    }

    public function getId(): ?int { return $this->id; }
    public function getTask(): ProxmoxTask { return $this->task; }
    public function getFamily(): int { return $this->family; }
    public function getAddress(): string { return ProxmoxIpRange::address($this->addressKey); }
    public function getPrefix(): int { return $this->prefix; }
    public function getGateway(): string { return $this->gateway; }
    public function getBridge(): string { return $this->bridge; }
    public function getVlan(): ?int { return $this->vlan; }
    public function getReservationSlot(): int { return $this->reservationSlot; }
}
