<?php

declare(strict_types=1);

namespace App\Module\Nodes\Domain\Entity;

use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'proxmox_guest_assignments')]
#[ORM\UniqueConstraint(name: 'uniq_proxmox_guest', columns: ['cluster_id', 'vm_id'])]
class ProxmoxGuestAssignment
{
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $assignedAt = null;

    public function getAssignedAt(): ?\DateTimeImmutable { return $this->assignedAt; }

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false)] private ProxmoxCluster $cluster,
        #[ORM\Column] private int $vmId,
        #[ORM\Column(length: 10)] private string $kind,
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private User $customer,
        #[ORM\Column(length: 64)] private string $identityTag,
        #[ORM\Column(length: 64, nullable: true)] private ?string $backupStorage = null,
    ) {
        $this->assignedAt = new \DateTimeImmutable();
        if (UserType::Customer !== $customer->getType() || !in_array($kind, ['qemu', 'lxc'], true) || $vmId < 100 || !preg_match('/^easywi-owned-[a-f0-9]{16}$/D', $identityTag)) {
            throw new \InvalidArgumentException('Invalid guest assignment.');
        }
    }

    public function getIdentityTag(): string
    {
        return $this->identityTag;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCluster(): ProxmoxCluster
    {
        return $this->cluster;
    }

    public function getVmId(): int
    {
        return $this->vmId;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getCustomer(): User
    {
        return $this->customer;
    }

    public function getBackupStorage(): ?string
    {
        return $this->backupStorage;
    }
}
