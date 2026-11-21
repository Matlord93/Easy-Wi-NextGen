<?php

declare(strict_types=1);

namespace App\Module\Nodes\Domain\Entity;

use App\Module\Nodes\Domain\Proxmox\ProxmoxIpRange;
use Doctrine\ORM\Mapping as ORM;

/** @psalm-api Doctrine entity and Twig view. */
#[ORM\Entity]
#[ORM\Table(name: 'proxmox_ip_pools')]
#[ORM\UniqueConstraint(name: 'uniq_pve_ip_pool_node_family', columns: ['cluster_id', 'node', 'family'])]
class ProxmoxIpPool
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\Column(name: 'first_address', length: 45)]
    private string $first;
    #[ORM\Column(name: 'last_address', length: 45)]
    private string $last;
    #[ORM\Column]
    private int $prefix;
    #[ORM\Column(length: 45)]
    private string $gateway;
    #[ORM\Column(length: 32)]
    private string $bridge;
    #[ORM\Column(nullable: true)]
    private ?int $vlan;
    #[ORM\Column]
    private bool $enabled;

    public function __construct(
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false)] private ProxmoxCluster $cluster,
        #[ORM\Column(length: 64)] private string $node,
        #[ORM\Column] private int $family,
        ProxmoxIpRange $range,
        string $bridge,
        ?int $vlan,
        bool $enabled,
    ) {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,62}$/D', $node)) {
            throw new \InvalidArgumentException('pve.error.node_offline');
        }
        $this->configure($range, $bridge, $vlan, $enabled);
    }

    final public function configure(ProxmoxIpRange $range, string $bridge, ?int $vlan, bool $enabled): void
    {
        if ($range->family !== $this->family || !preg_match('/^[A-Za-z0-9_.-]{1,32}$/D', $bridge) || (null !== $vlan && ($vlan < 1 || $vlan > 4094))) {
            throw new \InvalidArgumentException('pve.error.ip_pool_network');
        }
        $this->first = $range->first;
        $this->last = $range->last;
        $this->prefix = $range->prefix;
        $this->gateway = $range->gateway;
        $this->bridge = $bridge;
        $this->vlan = $vlan;
        $this->enabled = $enabled;
    }

    public function getId(): ?int { return $this->id; }
    public function getFamily(): int { return $this->family; }
    public function getFirst(): string { return $this->first; }
    public function getLast(): string { return $this->last; }
    public function getPrefix(): int { return $this->prefix; }
    public function getGateway(): string { return $this->gateway; }
    public function getBridge(): string { return $this->bridge; }
    public function getVlan(): ?int { return $this->vlan; }
    public function isEnabled(): bool { return $this->enabled; }
}
