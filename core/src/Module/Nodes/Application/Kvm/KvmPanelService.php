<?php

declare(strict_types=1);

namespace App\Module\Nodes\Application\Kvm;

use App\Module\AgentOrchestrator\Application\AgentJobDispatcherInterface;
use App\Module\AgentOrchestrator\Domain\Entity\AgentJob;
use App\Module\AgentOrchestrator\Domain\Enum\AgentJobStatus;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Nodes\Application\VmProvisioningSettings;
use App\Repository\AgentJobRepository;

/** The host owns the managed catalog. Agent jobs persist its observed state. */
final readonly class KvmPanelService
{
    private const UUID = '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D';
    private const SLUG = '/^[a-z][a-z0-9-]{0,47}$/D';
    public const ACTIONS = ['inspect', 'template.import', 'vm.create', 'vm.start', 'vm.shutdown', 'vm.stop', 'vm.reboot', 'vm.suspend', 'vm.resume', 'vm.autostart', 'vm.autostart-disable', 'vm.limits', 'vm.disk_grow', 'vm.network', 'vm.console.configure', 'vm.cloud_init', 'vm.boot_order', 'vm.guest_agent', 'vm.agent_status', 'snapshot.create', 'snapshot.revert', 'snapshot.delete', 'backup.create', 'backup.restore', 'backup.delete', 'network.create', 'network.start', 'network.stop', 'network.autostart', 'network.autostart-disable'];

    public function __construct(private AgentJobDispatcherInterface $dispatcher, private AgentJobRepository $jobs)
    {
    }

    public function availability(Agent $node): ?string
    {
        $cap = $node->getMetadata()['kvm_managed'] ?? [];
        if (1 !== ($cap['version'] ?? null) || true !== ($cap['supported'] ?? null)) {
            return 'kvm.update_agent';
        }
        if (Agent::STATUS_DECOMMISSIONED === $node->getStatus() || !$node->getLastHeartbeatAt() || $node->getLastHeartbeatAt() < new \DateTimeImmutable('-5 minutes')) {
            return 'kvm.offline';
        }

        return null;
    }

    public function inventory(Agent $node): ?array
    {
        // Stream past failed inspections without discarding the last observation.
        // Creation order also prevents a late retry of an old job replacing it.
        $rows = $this->jobs->createQueryBuilder('j')
            ->where('j.node = :node')
            ->andWhere('j.type = :type')
            ->andWhere('j.resultPayload IS NOT NULL')
            ->setParameter('node', $node)
            ->setParameter('type', 'kvm.managed')
            ->orderBy('j.createdAt', 'DESC')
            ->getQuery()->toIterable();
        foreach ($rows as $job) {
            if (is_array($job->getResultPayload()['inventory'] ?? null)) {
                return $job->getResultPayload()['inventory'];
            }
        }

        return null;
    }

    /** @return AgentJob[] */
    public function recent(Agent $node): array
    {
        return $this->jobs->findLatestForNodeAndTypes($node->getId(), ['kvm.managed'], 20);
    }

    public function submit(Agent $node, string $action, array $input): AgentJob
    {
        if ($error = $this->availability($node)) {
            throw new \InvalidArgumentException($error);
        }
        if (!in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException('kvm.invalid');
        }
        $payload = ['action' => $action];
        if ('inspect' !== $action) {
            $payload['operation_id'] = $this->text($input, 'operation_id', self::UUID);
        }
        if ('template.import' === $action) {
            $payload['template_id'] = $this->text($input, 'template_id', self::SLUG);
            $payload['version'] = $this->text($input, 'version', '/^[a-z0-9][a-z0-9.-]{0,47}$/D');
            if (str_contains($payload['template_id'], '--') || str_contains($payload['version'], '--')) {
                throw new \InvalidArgumentException('kvm.invalid');
            }
            $payload['sha256'] = $this->text($input, 'sha256', '/^[a-f0-9]{64}$/D');
            $url = trim((string) ($input['url'] ?? ''));
            $parts = parse_url($url);
            if (strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL) || 'https' !== ($parts['scheme'] ?? '') || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment'])) {
                throw new \InvalidArgumentException('kvm.invalid');
            }
            $payload['url'] = $url;
        } elseif ('vm.create' === $action) {
            $payload['label'] = trim((string) ($input['label'] ?? ''));
            if ('' === $payload['label'] || mb_strlen($payload['label']) > 120) {
                throw new \InvalidArgumentException('kvm.invalid');
            }
            $payload['template'] = $this->text($input, 'template', '/^[a-z][a-z0-9-]{0,47}--[a-z0-9][a-z0-9.-]{0,47}$/D');
            $payload['hostname'] = $this->text($input, 'hostname', self::SLUG);
            $payload['username'] = $this->text($input, 'username', '/^(?!root$)[a-z][a-z0-9_-]{0,30}$/D');
            if (isset($input['network_choice']) && is_string($input['network_choice'])) {
                [$input['network_type'], $input['network']] = array_pad(explode(':', $input['network_choice'], 2), 2, '');
            }
            $payload['network_type'] = $this->text($input, 'network_type', '/^(network|bridge)$/D');
            $payload['network'] = $this->text($input, 'network', '/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D');
            $payload['disk_gib'] = $this->integer($input, 'disk_gib', 1, 2048);
            $keys = array_values(array_filter(array_map('trim', explode("\n", (string) ($input['ssh_keys'] ?? '')))));
            if ([] === $keys || count($keys) > 8 || strlen(implode('', $keys)) > 32768) {
                throw new \InvalidArgumentException('kvm.invalid');
            }
            foreach ($keys as $key) {
                if (!preg_match('/^(ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp(256|384|521)) [A-Za-z0-9+\/=]+(?: .*)?$/D', $key)) {
                    throw new \InvalidArgumentException('kvm.invalid');
                }
            }
            $payload['ssh_keys'] = $keys;
        } elseif (str_starts_with($action, 'network.')) {
            $payload['network'] = $this->text($input, 'network', '/^easywi-[a-z0-9-]{1,33}$/D');
            if ('network.create' === $action) {
                $payload['mode'] = $this->text($input, 'mode', '/^(nat|isolated)$/D');
                $payload['cidr'] = $this->text($input, 'cidr', '/^\d{1,3}(\.\d{1,3}){3}\/(24|25|26|27|28)$/D');
            }
        } elseif ('inspect' !== $action) {
            $payload['vm_id'] = $this->text($input, 'vm_id', self::UUID);
            if ('vm.network' === $action) {
                if (($node->getMetadata()['kvm_managed']['network_config_version'] ?? null) !== 1) {
                    throw new \InvalidArgumentException('kvm.update_agent');
                }
                if ('1' !== ($input['confirm'] ?? null)) {
                    throw new \InvalidArgumentException('kvm.network_confirm');
                }
                if (is_string($input['network_choice'] ?? null)) {
                    [$input['network_type'], $input['network']] = array_pad(explode(':', $input['network_choice'], 2), 2, '');
                }
                foreach (['network_type', 'expected_network_type'] as $field) {
                    $payload[$field] = $this->text($input, $field, '/^(network|bridge)$/D');
                }
                foreach (['network', 'expected_network'] as $field) {
                    $payload[$field] = $this->text($input, $field, '/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D');
                }
                if ($payload['network'] === $payload['expected_network'] && $payload['network_type'] === $payload['expected_network_type']) {
                    throw new \InvalidArgumentException('kvm.network_unchanged');
                }
                $payload['confirm'] = '1';
            }
            if ('vm.disk_grow' === $action) {
                if (($node->getMetadata()['kvm_managed']['disk_grow_version'] ?? null) !== 1) {
                    throw new \InvalidArgumentException('kvm.update_agent');
                }
                $payload['disk_gib'] = $this->integer($input, 'disk_gib', 1, 2048);
                $payload['expected_disk_gib'] = $this->integer($input, 'expected_disk_gib', 1, 2048);
                if (($input['confirm'] ?? '') !== '1' || $payload['disk_gib'] <= $payload['expected_disk_gib']) {
                    throw new \InvalidArgumentException('kvm.disk_grow_only');
                }
                $payload['confirm'] = '1';
            }
            if ('vm.agent_status' === $action && ($node->getMetadata()['kvm_managed']['guest_agent_status_version'] ?? null) !== 1) {
                throw new \InvalidArgumentException('kvm.update_agent');
            }
            if (in_array($action, ['vm.cloud_init', 'vm.boot_order', 'vm.guest_agent'], true)) {
                if (($node->getMetadata()['kvm_managed']['config_version'] ?? null) !== 1) {
                    throw new \InvalidArgumentException('kvm.update_agent');
                }
                if ('vm.cloud_init' === $action) {
                    $payload['hostname'] = $this->text($input, 'hostname', self::SLUG);
                    $payload['ssh_keys'] = VmProvisioningSettings::publicKeys($input['ssh_keys'] ?? '');
                } elseif ('vm.boot_order' === $action) {
                    $payload['boot_order'] = $this->text($input, 'boot_order', '/^(disk|network)$/D');
                }
            }
            if (str_starts_with($action, 'snapshot.')) {
                $payload['snapshot'] = $this->text($input, 'snapshot', self::SLUG);
            }
            if (in_array($action, ['backup.restore', 'backup.delete'], true)) {
                $payload['backup_id'] = $this->text($input, 'backup_id', self::UUID);
            }
        }
        if (in_array($action, ['vm.create', 'vm.limits'], true)) {
            $payload['vcpus'] = $this->integer($input, 'vcpus', 1, 256);
            $payload['memory_mib'] = $this->integer($input, 'memory_mib', 128, 1048576);
        }
        // Repeated form submissions reuse completed jobs as well. The agent
        // independently serializes operations and caches UUID+payload results.
        $key = hash('sha256', $node->getId().':kvm.managed:'.json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
        if ('inspect' !== $action && $existing = $this->jobs->findLatestByIdempotencyKey($key)) {
            return $existing;
        }
        if ('vm.agent_status' === $action) {
            foreach ($this->recent($node) as $job) {
                if (($job->getPayload()['action'] ?? '') === $action && ($job->getPayload()['vm_id'] ?? '') === $payload['vm_id'] && (in_array($job->getStatus(), [AgentJobStatus::Queued, AgentJobStatus::Running], true) || $job->getCreatedAt() > new \DateTimeImmutable('-10 seconds'))) {
                    return $job;
                }
            }
        }
        foreach ($this->recent($node) as $job) {
            if (in_array($job->getStatus(), [AgentJobStatus::Queued, AgentJobStatus::Running], true)) {
                if ('inspect' === $action && 'inspect' === ($job->getPayload()['action'] ?? '')) {
                    return $job;
                }
                throw new \InvalidArgumentException('kvm.busy');
            }
        }

        return $this->dispatcher->dispatch($node, 'kvm.managed', $payload);
    }

    private function text(array $input, string $key, string $pattern): string
    {
        $value = $input[$key] ?? '';
        if (!is_string($value) || !preg_match($pattern, $value)) {
            throw new \InvalidArgumentException('kvm.invalid');
        }

        return $value;
    }

    private function integer(array $input, string $key, int $min, int $max): int
    {
        $value = filter_var($input[$key] ?? null, FILTER_VALIDATE_INT);
        if (false === $value || $value < $min || $value > $max) {
            throw new \InvalidArgumentException('kvm.invalid');
        }

        return $value;
    }
}
