<?php

declare(strict_types=1);

namespace App\Module\Nodes\Application\Kvm;

use App\Repository\QueryParameter;
use App\Repository\EntityQueryResult;
use App\Infrastructure\Config\JsonArray;
use App\Infrastructure\Config\JsonMap;
use App\Module\AgentOrchestrator\Application\AgentJobDispatcherInterface;
use App\Module\AgentOrchestrator\Domain\Entity\AgentJob;
use App\Module\AgentOrchestrator\Domain\Enum\AgentJobStatus;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Nodes\Application\VmProvisioningSettings;
use App\Module\Nodes\Application\VmRootPassword;
use App\Repository\AgentJobRepository;

/**
 * The host owns the managed catalog. Agent jobs persist its observed state.
 * @psalm-type KvmInventory = array{
 *     guests: list<array<array-key, mixed>>, templates: list<array<array-key, mixed>>,
 *     networks: list<array<array-key, mixed>>, ...<array-key, mixed>
 * }
 */
final readonly class KvmPanelService
{
    private const UUID = '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D';
    private const SLUG = '/^[a-z][a-z0-9-]{0,47}$/D';
    public const int REINSTALL_VERSION = 2;
    public const ACTIONS = ['inspect', 'host.setup', 'host.vm_number_range', 'host.address_pool', 'template.import', 'vm.create', 'vm.start', 'vm.shutdown', 'vm.stop', 'vm.reboot', 'vm.suspend', 'vm.resume', 'vm.autostart', 'vm.autostart-disable', 'vm.limits', 'vm.disk_grow', 'vm.network', 'vm.console.configure', 'vm.cloud_init', 'vm.boot_order', 'vm.guest_agent', 'vm.agent_status', 'vm.root_password', 'vm.address_switch', 'vm.reinstall', 'vm.reinstall_rollback', 'snapshot.create', 'snapshot.revert', 'snapshot.delete', 'backup.create', 'backup.restore', 'backup.delete', 'network.create', 'network.start', 'network.stop', 'network.autostart', 'network.autostart-disable'];

    public function __construct(private AgentJobDispatcherInterface $dispatcher, private AgentJobRepository $jobs)
    {
    }

    public function availability(Agent $node): ?string
    {
        $cap = JsonArray::orEmpty($node->getMetadata()['kvm_managed'] ?? null);
        if (1 !== ($cap['version'] ?? null) || true !== ($cap['supported'] ?? null)) {
            return 'kvm.update_agent';
        }
        if (Agent::STATUS_DECOMMISSIONED === $node->getStatus() || !$node->getLastHeartbeatAt() || $node->getLastHeartbeatAt() < new \DateTimeImmutable('-5 minutes')) {
            return 'kvm.offline';
        }

        return null;
    }

    /** @return KvmInventory|null */
    public function inventory(Agent $node): ?array
    {
        // Stream past failed inspections without discarding the last observation.
        // Creation order also prevents a late retry of an old job replacing it.
        $rows = EntityQueryResult::iterate($this->jobs->createQueryBuilder('j')
            ->where('j.node = :node')
            ->andWhere('j.type = :type')
            ->andWhere('j.resultPayload IS NOT NULL')
            ->setParameter('node', QueryParameter::entityId($node->getId(), \App\Module\Core\Domain\Entity\Agent::class))
            ->setParameter('type', 'kvm.managed')
            ->orderBy('j.createdAt', 'DESC')
            ->getQuery(), AgentJob::class);
        foreach ($rows as $job) {
            $inventory = JsonArray::fromValue($job->getResultPayload()['inventory'] ?? null);
            if ($inventory !== null) {
                $inventory['guests'] = self::inventoryRows($inventory['guests'] ?? null);
                $inventory['templates'] = self::inventoryRows($inventory['templates'] ?? null);
                $inventory['networks'] = self::inventoryRows($inventory['networks'] ?? null);
                return $inventory;
            }
        }

        return null;
    }

    /** @return list<array<array-key, mixed>> */
    private static function inventoryRows(mixed $value): array
    {
        $rows = JsonArray::orEmpty($value);
        $result = [];
        foreach (array_keys($rows) as $key) {
            $row = JsonArray::fromValue($rows[$key]);
            if ($row !== null) {
                $result[] = $row;
            }
        }
        return $result;
    }

    /** @return AgentJob[] */
    public function recent(Agent $node): array
    {
        return $this->jobs->findLatestForNodeAndTypes($node->getId(), ['kvm.managed'], 20);
    }

    /** @param KvmInventory|null $inventory */
    public function reinstallationForRollback(Agent $node, string $vmId, ?array $inventory): ?string
    {
        if (1 !== ($node->getMetadata()['kvm_managed']['reinstall_rollback_version'] ?? null)) {
            return null;
        }
        $pending = JsonArray::orEmpty($inventory['pending_operations'] ?? null);
        $recovery = JsonMap::strings(JsonArray::orEmpty($inventory['reinstall_rollback'] ?? null));
        $operation = $recovery['operation_id'] ?? '';

        return [$operation] === $pending && $vmId === ($recovery['vm_id'] ?? null) && preg_match(self::UUID, $operation) ? $operation : null;
    }

    public function hasAddressPool(Agent $node, string $kind, string $network): bool
    {
        if (null !== $this->availability($node) || 1 !== ($node->getMetadata()['kvm_managed']['address_pool_version'] ?? null)) {
            return false;
        }
        foreach (self::inventoryRows($this->inventory($node)['address_pools'] ?? null) as $pool) {
            if (($pool['network_type'] ?? null) === $kind && ($pool['network'] ?? null) === $network && in_array($pool['family'] ?? null, [4, 6], true)) {
                return true;
            }
        }

        return false;
    }

    public function submit(Agent $node, string $action, array $input): AgentJob
    {
        $error = $this->availability($node);
        if (null !== $error) {
            throw new \InvalidArgumentException($error);
        }
        if (!in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException('kvm.invalid');
        }
        $payload = ['action' => $action];
        $operationId = null;
        if ('inspect' !== $action) {
            $operationId = $this->text($input, 'operation_id', self::UUID);
            $payload['operation_id'] = $operationId;
        }
        if (in_array($action, ['host.address_pool', 'vm.create', 'vm.network'], true) && null !== ($choice = JsonMap::strings($input)['network_choice'] ?? null)) {
            [$input['network_type'], $input['network']] = array_pad(explode(':', $choice, 2), 2, '');
        }
        if ('host.setup' === $action) {
            if (!$node->hasKvmRole()) {
                throw new \InvalidArgumentException('kvm.role_required');
            }
            if (1 !== ($node->getMetadata()['kvm_managed']['host_setup_version'] ?? null)) {
                throw new \InvalidArgumentException('kvm.update_agent');
            }
            if ('1' !== ($input['confirm'] ?? null)) {
                throw new \InvalidArgumentException('kvm.host_setup_confirm');
            }
            $payload['confirm'] = '1';
            $payload['role'] = 'KVM';
        } elseif ('host.vm_number_range' === $action) {
            if (1 !== ($node->getMetadata()['kvm_managed']['vm_number_version'] ?? null)) {
                throw new \InvalidArgumentException('kvm.update_agent');
            }
            if ('' !== ($input['first'] ?? '') || '' !== ($input['last'] ?? '')) {
                $payload['first'] = $this->integer($input, 'first', 100, 999999999);
                $payload['last'] = $this->integer($input, 'last', $payload['first'], 999999999);
            }
        } elseif ('host.address_pool' === $action) {
            if (1 !== ($node->getMetadata()['kvm_managed']['address_pool_version'] ?? null)) {
                throw new \InvalidArgumentException('kvm.update_agent');
            }
            $payload['network_type'] = $this->text($input, 'network_type', '/^(network|bridge)$/D');
            $payload['network'] = $this->text($input, 'network', '/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D');
            $payload['family'] = $this->integer($input, 'family', 4, 6);
            if (5 === $payload['family']) {
                throw new \InvalidArgumentException('kvm.invalid');
            }
            if ('1' === ($input['remove'] ?? null)) {
                $payload['remove'] = '1';
            } else {
                $v4 = 4 === $payload['family'];
                $payload['prefix'] = $this->integer($input, 'prefix', 1, $v4 ? 30 : 127);
                foreach (['first', 'last', 'gateway', 'dns'] as $field) {
                    $payload[$field] = $this->ipAddress($input[$field] ?? null, $v4 ? FILTER_FLAG_IPV4 : FILTER_FLAG_IPV6);
                }
            }
        } elseif ('template.import' === $action) {
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
            if ('1' === ($input['start_after_install'] ?? null)) {
                if (1 !== ($node->getMetadata()['kvm_managed']['install_access_version'] ?? null)) {
                    throw new \InvalidArgumentException('kvm.update_agent');
                }
                $payload['start_after_install'] = '1';
                if (isset($input['install_password_hash'])) {
                    $payload['install_password_hash'] = $this->text($input, 'install_password_hash', VmRootPassword::HASH_PATTERN);
                }
            }
            if ('' !== ($input['vm_number'] ?? '') && null !== ($input['vm_number'] ?? null)) {
                if (1 !== ($node->getMetadata()['kvm_managed']['vm_number_version'] ?? null)) {
                    throw new \InvalidArgumentException('kvm.update_agent');
                }
                $payload['vm_number'] = $this->integer($input, 'vm_number', 100, 999999999);
            }
            $payload['label'] = trim((string) ($input['label'] ?? ''));
            if ('' === $payload['label'] || mb_strlen($payload['label']) > 120) {
                throw new \InvalidArgumentException('kvm.invalid');
            }
            $payload['template'] = $this->text($input, 'template', '/^[a-z][a-z0-9-]{0,47}--[a-z0-9][a-z0-9.-]{0,47}$/D');
            $payload['hostname'] = $this->text($input, 'hostname', self::SLUG);
            $payload['username'] = $this->text($input, 'username', '/^(?!root$)[a-z][a-z0-9_-]{0,30}$/D');
            if ('pool' === ($input['address_mode'] ?? null)) {
                if (1 !== ($node->getMetadata()['kvm_managed']['address_pool_version'] ?? null)) {
                    throw new \InvalidArgumentException('kvm.update_agent');
                }
                $payload['address_mode'] = 'pool';
            } elseif ('dhcp' !== ($input['address_mode'] ?? 'dhcp')) {
                throw new \InvalidArgumentException('kvm.invalid');
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
                if ('' !== ($input['dhcp_start'] ?? '')) {
                    if (1 !== ($node->getMetadata()['kvm_managed']['address_pool_version'] ?? null)) {
                        throw new \InvalidArgumentException('kvm.update_agent');
                    }
                    $payload['dhcp_start'] = $this->ipAddress($input['dhcp_start'], FILTER_FLAG_IPV4);
                }
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
            if ('vm.root_password' === $action) {
                if (1 !== ($node->getMetadata()['kvm_managed']['root_password_version'] ?? null)) {
                    throw new \InvalidArgumentException('kvm.update_agent');
                }
                if (($input['confirm'] ?? '') !== '1') {
                    throw new \InvalidArgumentException('kvm.customer_confirm');
                }
                $payload['password_hash'] = $this->text($input, 'password_hash', VmRootPassword::HASH_PATTERN);
                $payload['confirm'] = '1';
            }
            if ('vm.reinstall' === $action) {
                if (self::REINSTALL_VERSION !== ($node->getMetadata()['kvm_managed']['reinstall_version'] ?? null)) {
                    throw new \InvalidArgumentException('kvm.update_agent');
                }
                if (($input['confirm'] ?? '') !== '1') {
                    throw new \InvalidArgumentException('kvm.customer_confirm');
                }
                $payload['template'] = $this->text($input, 'template', '/^[a-z][a-z0-9-]{0,47}--[a-z0-9][a-z0-9.-]{0,47}$/D');
                $payload['confirm'] = '1';
                if (isset($input['install_password_hash'])) {
                    if (1 !== ($node->getMetadata()['kvm_managed']['install_access_version'] ?? null)) {
                        throw new \InvalidArgumentException('kvm.update_agent');
                    }
                    $payload['install_password_hash'] = $this->text($input, 'install_password_hash', VmRootPassword::HASH_PATTERN);
                }
            }
            if ('vm.reinstall_rollback' === $action) {
                $origin = $this->text($input, 'reinstall_operation_id', self::UUID);
                if ('1' !== ($input['confirm'] ?? null) || $origin === $operationId || $origin !== $this->reinstallationForRollback($node, $payload['vm_id'], $this->inventory($node))) {
                    throw new \InvalidArgumentException('kvm.reinstall_rollback_unavailable');
                }
                $payload['reinstall_operation_id'] = $origin;
                $payload['confirm'] = '1';
            }
            if ('vm.address_switch' === $action) {
                if (1 !== ($node->getMetadata()['kvm_managed']['address_switch_version'] ?? null)) {
                    throw new \InvalidArgumentException('kvm.update_agent');
                }
                $address = $input['expected_address'] ?? null;
                if (!is_string($address) || !filter_var($address, FILTER_VALIDATE_IP) || '1' !== ($input['confirm'] ?? '')) {
                    throw new \InvalidArgumentException('vm_config.address_switch_unavailable');
                }
                $payload['expected_address'] = $address;
                $payload['confirm'] = '1';
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
                if (($job->getPayload()['action'] ?? '') === $action && ($job->getPayload()['vm_id'] ?? '') === ($payload['vm_id'] ?? null) && (in_array($job->getStatus(), [AgentJobStatus::Queued, AgentJobStatus::Running], true) || $job->getCreatedAt() > new \DateTimeImmutable('-10 seconds'))) {
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

    /** @param non-empty-string $pattern */
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

    private function ipAddress(mixed $value, int $family): string
    {
        if (!is_string($value) || false === filter_var(trim($value), FILTER_VALIDATE_IP, $family)) {
            throw new \InvalidArgumentException('kvm.invalid');
        }

        return trim($value);
    }
}
