<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Agent;
use App\Entity\FirewallState;
use App\Entity\Job;
use App\Repository\FirewallStateRepository;
use Doctrine\ORM\EntityManagerInterface;

final class FirewallStateManager
{
    public function __construct(
        private readonly FirewallStateRepository $firewallStateRepository,
        private readonly AuditLogger $auditLogger,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param int[] $ports
     */
    public function applyOpenPorts(Agent $agent, array $ports): ?FirewallState
    {
        $ports = $this->sanitizePorts($ports);
        if ($ports === []) {
            return null;
        }

        $state = $this->firewallStateRepository->findOneBy(['node' => $agent]);
        if ($state === null) {
            $state = new FirewallState($agent, []);
        }

        $current = $this->sanitizePorts($state->getPorts());
        $updated = array_values(array_unique(array_merge($current, $ports)));
        sort($updated);

        if ($updated === $current) {
            return $state;
        }

        $state->setPorts($updated);
        $this->entityManager->persist($state);

        $this->auditLogger->log(null, 'firewall.state_updated', [
            'agent_id' => $agent->getId(),
            'action' => 'open_ports',
            'ports' => $ports,
        ]);

        return $state;
    }

    /**
     * @param int[] $ports
     */
    public function applyClosePorts(Agent $agent, array $ports): ?FirewallState
    {
        $ports = $this->sanitizePorts($ports);
        if ($ports === []) {
            return null;
        }

        $state = $this->firewallStateRepository->findOneBy(['node' => $agent]);
        if ($state === null) {
            return null;
        }

        $current = $this->sanitizePorts($state->getPorts());
        $updated = array_values(array_diff($current, $ports));
        sort($updated);

        if ($updated === $current) {
            return $state;
        }

        $state->setPorts($updated);
        $this->entityManager->persist($state);

        $this->auditLogger->log(null, 'firewall.state_updated', [
            'agent_id' => $agent->getId(),
            'action' => 'close_ports',
            'ports' => $ports,
        ]);

        return $state;
    }

    /**
     * @return int[]
     */
    public function portsFromJob(Job $job): array
    {
        $payload = $job->getPayload();
        $raw = is_string($payload['ports'] ?? null) ? $payload['ports'] : '';
        if ($raw === '') {
            return [];
        }

        $ports = [];
        foreach (explode(',', $raw) as $entry) {
            $entry = trim($entry);
            if ($entry === '' || !ctype_digit($entry)) {
                continue;
            }
            $port = (int) $entry;
            if ($port <= 0 || $port > 65535) {
                continue;
            }
            $ports[] = $port;
        }

        return $this->sanitizePorts($ports);
    }

    /**
     * @param int[] $ports
     * @return int[]
     */
    private function sanitizePorts(array $ports): array
    {
        $ports = array_values(array_unique(array_filter($ports, fn (int $port) => $port > 0 && $port <= 65535)));
        sort($ports);
        return $ports;
    }
}
