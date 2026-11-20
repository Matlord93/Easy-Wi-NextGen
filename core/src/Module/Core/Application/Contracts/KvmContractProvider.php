<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Contracts;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\ShopContract;
use App\Module\Core\Domain\Enum\ShopProductKind;
use App\Module\Nodes\Application\Kvm\KvmPanelService;
use App\Module\Nodes\Domain\Entity\KvmGuestAssignment;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class KvmContractProvider implements ContractResourceProvider
{
    public function __construct(private EntityManagerInterface $em, private KvmPanelService $kvm, private ContractJobs $jobs)
    {
    }

    #[\Override]
    public function kinds(): array
    {
        return [ShopProductKind::Kvm];
    }

    #[\Override]
    public function provision(ShopContract $contract): void
    {
        $c = $contract->getConfiguration();
        $node = $this->em->find(Agent::class, (string) $c['node_id']);
        if (null === $node) {
            throw new \DomainException('KVM_NODE_MISSING');
        }
        $id = Uuid::v4()->toRfc4122();
        $job = $this->kvm->submit($node, 'vm.create', ['operation_id' => $id, 'label' => (string) $c['name'].' #'.($contract->getId() ?? 0), 'template' => (string) ($c['kvm_template'] ?? ''), 'hostname' => (string) $c['hostname'], 'username' => 'easywi', 'network_type' => (string) ($c['network_type'] ?? 'network'), 'network' => (string) ($c['network'] ?? 'default'), 'ssh_keys' => (string) $c['ssh_keys'], 'vcpus' => (int) $c['cpu'], 'memory_mib' => (int) $c['ram_mib'], 'disk_gib' => (int) $c['disk']]);
        $assignment = new KvmGuestAssignment($node, $id, $contract->getCustomer());
        $this->em->persist($assignment);
        $this->em->flush();
        $contract->bind($id, $job->getId(), 'agent');
    }

    #[\Override]
    public function readyAt(ShopContract $contract): ?\DateTimeImmutable
    {
        return $this->jobs->readyAt($contract);
    }

    #[\Override]
    public function stop(ShopContract $contract): bool
    {
        if (null === $contract->getResourceId()) {
            return true;
        }
        if (null !== $contract->getStopJobId()) {
            return null !== $this->jobs->readyAt($contract, true);
        }
        $config = $contract->getConfiguration();
        $node = $this->em->find(Agent::class, (string) $config['node_id']);
        if (null === $node) {
            return false;
        }
        $assignment = $this->em->getRepository(KvmGuestAssignment::class)->findOneBy(['node' => $node, 'vmUuid' => $contract->getResourceId()]);
        if (null !== $assignment) {
            $this->em->remove($assignment);
        }
        if (null === $this->jobs->readyAt($contract) && 'PROVISION_FAILED' !== $contract->getError()) {
            return false;
        }
        $job = $this->kvm->submit($node, 'vm.stop', ['operation_id' => Uuid::v4()->toRfc4122(), 'vm_id' => $contract->getResourceId()]);
        $contract->stoppingJob($job->getId(), 'agent');

        return false;
    }
}
