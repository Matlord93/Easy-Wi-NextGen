<?php

declare(strict_types=1);

namespace App\Module\Nodes\Application\Kvm;

use App\Module\AgentOrchestrator\Application\AgentJobDispatcherInterface;
use App\Module\AgentOrchestrator\Domain\Entity\AgentJob;
use App\Module\Core\Domain\Entity\Agent;

final readonly class KvmJobService
{
    public function __construct(private AgentJobDispatcherInterface $dispatcher)
    {
    }

    public function listVirtualMachines(Agent $node): AgentJob
    {
        return $this->dispatcher->dispatch($node, 'kvm.vm.list', []);
    }

    public function inspect(Agent $node, string $name): AgentJob
    {
        return $this->dispatcher->dispatch($node, 'kvm.vm.status', ['name' => $name]);
    }

    public function powerAction(Agent $node, string $name, string $action): AgentJob
    {
        if (!in_array($action, ['start', 'shutdown', 'stop', 'reboot', 'suspend', 'resume', 'autostart', 'autostart-disable'], true)) {
            throw new \InvalidArgumentException('Unsupported KVM VM action.');
        }

        return $this->dispatcher->dispatch($node, 'kvm.vm.action', ['name' => $name, 'action' => $action]);
    }

    public function listSnapshots(Agent $node, string $name): AgentJob
    {
        return $this->dispatcher->dispatch($node, 'kvm.snapshot.list', ['name' => $name]);
    }

    public function snapshot(Agent $node, string $name, string $snapshot, string $action): AgentJob
    {
        if (!in_array($action, ['create', 'delete', 'revert'], true)) {
            throw new \InvalidArgumentException('Unsupported KVM snapshot action.');
        }

        return $this->dispatcher->dispatch($node, 'kvm.snapshot.'.$action, [
            'name' => $name,
            'snapshot' => $snapshot,
        ]);
    }

    public function applyLimits(Agent $node, string $name, int $vcpus, int $memoryMiB): AgentJob
    {
        if ($vcpus < 1 || $vcpus > 1024) {
            throw new \InvalidArgumentException('KVM vCPU limit must be between 1 and 1024.');
        }
        if ($memoryMiB < 128 || $memoryMiB > 16 * 1024 * 1024) {
            throw new \InvalidArgumentException('KVM memory limit must be between 128 MiB and 16 TiB.');
        }

        return $this->dispatcher->dispatch($node, 'kvm.vm.limits.apply', [
            'name' => $name,
            'vcpus' => $vcpus,
            'memory_mib' => $memoryMiB,
        ]);
    }

    public function listNetworks(Agent $node): AgentJob
    {
        return $this->dispatcher->dispatch($node, 'kvm.network.list', []);
    }

    public function networkAction(Agent $node, string $network, string $action): AgentJob
    {
        if (!in_array($action, ['start', 'stop', 'autostart', 'autostart-disable'], true)) {
            throw new \InvalidArgumentException('Unsupported KVM network action.');
        }

        return $this->dispatcher->dispatch($node, 'kvm.network.action', [
            'network' => $network,
            'action' => $action,
        ]);
    }
}
