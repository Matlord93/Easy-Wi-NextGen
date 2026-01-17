<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\FirewallState;
use App\Module\Core\Domain\Entity\Job;
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

        $state = $this->applyRules($agent, $this->buildRulesFromPorts($ports, 'open'));

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

        $state = $this->applyRules($agent, $this->buildRulesFromPorts($ports, 'closed'));
        if ($state === null) {
            return null;
        }

        $this->auditLogger->log(null, 'firewall.state_updated', [
            'agent_id' => $agent->getId(),
            'action' => 'close_ports',
            'ports' => $ports,
        ]);

        return $state;
    }

    public function applyFirewallJobResult(Job $job, Agent $agent, array $output): ?FirewallState
    {
        if (!in_array($job->getType(), ['firewall.open_ports', 'firewall.close_ports'], true)) {
            return null;
        }

        $rules = $this->rulesFromOutput($output);
        if ($rules === []) {
            $ports = $this->portsFromOutput($output);
            if ($ports === []) {
                $ports = $this->portsFromJob($job);
            }
            $status = $job->getType() === 'firewall.open_ports' ? 'open' : 'closed';
            $rules = $this->buildRulesFromPorts($ports, $status);
        }

        if ($rules === []) {
            return null;
        }

        $state = $this->applyRules($agent, $rules);
        if ($state === null) {
            return null;
        }

        $ports = array_values(array_unique(array_map(static fn (array $rule): int => $rule['port'], $rules)));
        sort($ports);

        $this->auditLogger->log(null, 'firewall.state_updated', [
            'agent_id' => $agent->getId(),
            'action' => $job->getType() === 'firewall.open_ports' ? 'open_ports' : 'close_ports',
            'ports' => $ports,
            'job_id' => $job->getId(),
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
     * @return int[]
     */
    private function portsFromOutput(array $output): array
    {
        $raw = is_string($output['ports'] ?? null) ? $output['ports'] : '';
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
     * @return array<int, array{port: int, protocol: string, status: string}>
     */
    private function rulesFromOutput(array $output): array
    {
        $raw = $output['rules'] ?? null;
        $decoded = null;

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
        } elseif (is_array($raw)) {
            $decoded = $raw;
        }

        if (!is_array($decoded)) {
            return [];
        }

        return $this->normalizeRules($decoded);
    }

    /**
     * @param int[] $ports
     * @return array<int, array{port: int, protocol: string, status: string}>
     */
    private function buildRulesFromPorts(array $ports, string $status): array
    {
        $ports = $this->sanitizePorts($ports);
        if ($ports === []) {
            return [];
        }

        $rules = [];
        foreach ($ports as $port) {
            $rules[] = ['port' => $port, 'protocol' => 'tcp', 'status' => $status];
            $rules[] = ['port' => $port, 'protocol' => 'udp', 'status' => $status];
        }

        return $rules;
    }

    /**
     * @param array<int, array<string, mixed>> $rules
     * @return array<int, array{port: int, protocol: string, status: string}>
     */
    private function normalizeRules(array $rules): array
    {
        $normalized = [];
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $port = $rule['port'] ?? null;
            $protocol = is_string($rule['protocol'] ?? null) ? strtolower($rule['protocol']) : '';
            $status = is_string($rule['status'] ?? null) ? strtolower($rule['status']) : '';

            if (!is_int($port) && !is_numeric($port)) {
                continue;
            }

            $port = (int) $port;
            if ($port <= 0 || $port > 65535) {
                continue;
            }

            if (!in_array($protocol, ['tcp', 'udp'], true)) {
                continue;
            }

            if (!in_array($status, ['open', 'closed'], true)) {
                continue;
            }

            $normalized[] = [
                'port' => $port,
                'protocol' => $protocol,
                'status' => $status,
            ];
        }

        return $normalized;
    }

    /**
     * @param array<int, array{port: int, protocol: string, status: string}> $rules
     */
    private function applyRules(Agent $agent, array $rules): ?FirewallState
    {
        $rules = $this->normalizeRules($rules);
        if ($rules === []) {
            return null;
        }

        $state = $this->firewallStateRepository->findOneBy(['node' => $agent]);
        if ($state === null) {
            $state = new FirewallState($agent, [], []);
        }

        $existing = [];
        foreach ($this->normalizeRules($state->getRules()) as $rule) {
            $existing[$this->ruleKey($rule)] = $rule;
        }

        foreach ($rules as $rule) {
            $existing[$this->ruleKey($rule)] = $rule;
        }

        $updatedRules = array_values($existing);
        usort($updatedRules, function (array $left, array $right): int {
            $portCompare = $left['port'] <=> $right['port'];
            if ($portCompare !== 0) {
                return $portCompare;
            }
            return $left['protocol'] <=> $right['protocol'];
        });

        $openPorts = [];
        foreach ($updatedRules as $rule) {
            if ($rule['status'] === 'open') {
                $openPorts[$rule['port']] = true;
            }
        }

        $ports = array_keys($openPorts);
        sort($ports);

        $state->setRules($updatedRules);
        $state->setPorts($ports);
        $this->entityManager->persist($state);

        return $state;
    }

    /**
     * @param array{port: int, protocol: string, status: string} $rule
     */
    private function ruleKey(array $rule): string
    {
        return sprintf('%d/%s', $rule['port'], $rule['protocol']);
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
