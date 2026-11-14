<?php

declare(strict_types=1);

namespace App\Module\Nodes\Domain\Entity;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\User;
use Doctrine\ORM\Mapping as ORM;

/** @psalm-api Hydrated and persisted through Doctrine metadata. */
#[ORM\Entity, ORM\Table(name: 'kvm_guest_assignments')]
#[ORM\UniqueConstraint(name: 'uniq_kvm_guest_owner', columns: ['node_id', 'vm_uuid'])]
class KvmGuestAssignment
{
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $assignedAt = null;

    public function getAssignedAt(): ?\DateTimeImmutable { return $this->assignedAt; }

    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    public function __construct(
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private Agent $node,
        #[ORM\Column(length: 36)] private string $vmUuid,
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private User $customer,
    ) {
        $this->assignedAt = new \DateTimeImmutable();
        if (!$customer->isCustomer() || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $vmUuid)) {
            throw new \InvalidArgumentException('Invalid KVM guest assignment.');
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNode(): Agent
    {
        return $this->node;
    }

    public function getVmUuid(): string
    {
        return $this->vmUuid;
    }

    public function getCustomer(): User
    {
        return $this->customer;
    }
}
