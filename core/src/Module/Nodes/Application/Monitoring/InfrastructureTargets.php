<?php

declare(strict_types=1);

namespace App\Module\Nodes\Application\Monitoring;

use App\Infrastructure\Doctrine\EntityIdentifier;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Nodes\Domain\Entity\KvmGuestAssignment;
use App\Module\Nodes\Domain\Entity\ProxmoxCluster;
use App\Module\Nodes\Domain\Entity\ProxmoxGuestAssignment;
use Doctrine\ORM\EntityManagerInterface;

final readonly class InfrastructureTargets
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function kvm(Agent $node, string $uuid, string $label = ''): InfrastructureTarget
    {
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $uuid)) {
            throw new \InvalidArgumentException('Invalid VM UUID.');
        }
        $owner = $this->em->getRepository(KvmGuestAssignment::class)->findOneBy(['node' => $node, 'vmUuid' => $uuid]);

        return new InfrastructureTarget('kvm', $node->getId(), 'vm', $uuid, $owner ? 'assignment:'.EntityIdentifier::require($owner->getId()).':customer:'.EntityIdentifier::require($owner->getCustomer()->getId()) : 'unassigned', $label ?: $uuid, '/admin/kvm/nodes/'.rawurlencode($node->getId()).'/guests/'.$uuid, $owner?->getCustomer(), $owner ? '/customer/kvm/'.EntityIdentifier::require($owner->getId()) : null, $owner?->getAssignedAt()?->getTimestamp());
    }

    public function node(Agent $node): InfrastructureTarget
    {
        return new InfrastructureTarget('kvm', $node->getId(), 'node', $node->getId(), 'node', $node->getName() ?: $node->getId(), '/admin/kvm/nodes/'.rawurlencode($node->getId()));
    }

    public function proxmox(ProxmoxCluster $cluster, string $kind, string $ref, string $label = ''): InfrastructureTarget
    {
        if (!in_array($kind, ['node', 'qemu', 'lxc'], true) || ('node' === $kind ? !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,62}$/D', $ref) : !preg_match('/^[1-9][0-9]{2,8}$/D', $ref))) {
            throw new \InvalidArgumentException('Invalid Proxmox resource.');
        }
        $owner = 'node' === $kind ? null : $this->em->getRepository(ProxmoxGuestAssignment::class)->findOneBy(['cluster' => $cluster, 'kind' => $kind, 'vmId' => (int) $ref]);
        $url = '/admin/proxmox/'.EntityIdentifier::require($cluster->getId()).('node' === $kind ? '#pve-node-'.rawurlencode($ref) : '/guests/'.$kind.'/'.$ref);

        return new InfrastructureTarget('proxmox', (string) $cluster->getId(), $kind, $ref, $owner ? 'assignment:'.EntityIdentifier::require($owner->getId()).':customer:'.EntityIdentifier::require($owner->getCustomer()->getId()).':'.$owner->getIdentityTag() : 'unassigned', $label ?: $ref, $url, $owner?->getCustomer(), $owner ? '/customer/proxmox/'.EntityIdentifier::require($owner->getId()) : null, $owner?->getAssignedAt()?->getTimestamp());
    }
}
