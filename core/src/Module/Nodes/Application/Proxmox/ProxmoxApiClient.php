<?php

declare(strict_types=1);

namespace App\Module\Nodes\Application\Proxmox;

use App\Infrastructure\Config\JsonArray;
use App\Infrastructure\Config\JsonMap;
use App\Module\Nodes\Domain\Proxmox\ProxmoxApiException;
use App\Module\Nodes\Domain\Proxmox\ProxmoxConnection;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Small, stateless adapter around the Proxmox VE JSON API.
 *
 * Authentication data is deliberately supplied per connection, allowing the
 * control plane to manage multiple clusters without global mutable state.
 */
final readonly class ProxmoxApiClient
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
    ) {
    }

    /** @return list<array<array-key, mixed>> */
    public function nodes(ProxmoxConnection $connection): array
    {
        return $this->list($connection, '/nodes');
    }

    /** @return array<string, mixed> */
    public function privilegesAt(ProxmoxConnection $connection, string $path): array
    {
        $permissions = $this->item($connection, '/access/permissions?'.http_build_query(['path' => $path]));

        return JsonMap::fromValue($permissions[$path] ?? null) ?? [];
    }

    public function hasPrivilege(ProxmoxConnection $connection, string $path, string $privilege): bool
    {
        $privileges = $this->privilegesAt($connection, $path);

        // A privilege granted only at this path has a propagation flag of 0.
        return isset($privileges[$privilege]);
    }

    public function hasRootPasswordPermission(ProxmoxConnection $connection, int $vmId): bool
    {
        return $this->hasPrivilege($connection, '/vms/'.$vmId, 'VM.GuestAgent.Unrestricted');
    }

    /** @return list<array<array-key, mixed>> */
    public function activeTasks(ProxmoxConnection $connection, string $node, ?int $vmId): array
    {
        return $this->list($connection, '/nodes/'.rawurlencode($node).'/tasks', ['source' => 'active', 'limit' => 1] + (null === $vmId ? [] : ['vmid' => $vmId]));
    }

    /** @return list<array<array-key, mixed>> */
    public function clusterResources(ProxmoxConnection $connection, ?string $type = null): array
    {
        return $this->list($connection, '/cluster/resources', null === $type ? [] : ['type' => $type]);
    }

    /** @return array<array-key, mixed> */
    public function status(ProxmoxConnection $connection, string $node, int $vmId, string $kind = 'qemu'): array
    {
        $this->assertGuestKind($kind);

        return $this->item($connection, sprintf('/nodes/%s/%s/%d/status/current', rawurlencode($node), $kind, $vmId));
    }

    /** @return array<array-key, mixed> */
    public function changePowerState(ProxmoxConnection $connection, string $node, int $vmId, string $action, string $kind = 'qemu'): array
    {
        $this->assertGuestKind($kind);
        if (!in_array($action, ['start', 'stop', 'shutdown', 'reboot', 'reset'], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported Proxmox power action "%s".', $action));
        }

        return $this->item($connection, sprintf('/nodes/%s/%s/%d/status/%s', rawurlencode($node), $kind, $vmId, $action), 'POST');
    }

    /** @return list<array<array-key, mixed>> */
    public function snapshots(ProxmoxConnection $connection, string $node, int $vmId, string $kind = 'qemu'): array
    {
        $this->assertGuestKind($kind);

        return $this->list($connection, sprintf('/nodes/%s/%s/%d/snapshot', rawurlencode($node), $kind, $vmId));
    }

    /** @return array<array-key, mixed> */
    public function createSnapshot(ProxmoxConnection $connection, string $node, int $vmId, string $name, ?string $description = null, string $kind = 'qemu'): array
    {
        $this->assertGuestKind($kind);
        $name = trim($name);
        if ('current' === $name || '' === $name || 1 !== preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,39}$/D', $name)) {
            throw new \InvalidArgumentException('Snapshot names must contain 1-40 letters, numbers, underscores, or hyphens.');
        }

        $body = ['snapname' => $name];
        if (null !== $description && '' !== trim($description)) {
            $body['description'] = trim($description);
        }

        return $this->item($connection, sprintf('/nodes/%s/%s/%d/snapshot', rawurlencode($node), $kind, $vmId), 'POST', $body);
    }

    /** @return list<array<array-key, mixed>> */
    public function storageContent(ProxmoxConnection $connection, string $node, string $storage, ?string $content = null, ?int $vmId = null): array
    {
        return $this->list(
            $connection,
            sprintf('/nodes/%s/storage/%s/content', rawurlencode($node), rawurlencode($storage)),
            (null === $content ? [] : ['content' => $content]) + (null === $vmId ? [] : ['vmid' => $vmId]),
        );
    }

    /** @return list<array<array-key, mixed>> */
    public function storages(ProxmoxConnection $connection, string $node): array
    {
        return $this->list($connection, '/nodes/'.rawurlencode($node).'/storage', ['enabled' => 1]);
    }

    /** @return list<array<array-key, mixed>> */
    public function networks(ProxmoxConnection $connection, string $node): array
    {
        return $this->list($connection, '/nodes/'.rawurlencode($node).'/network', ['type' => 'bridge']);
    }

    /** @return array<array-key, mixed> */
    public function guestConfig(ProxmoxConnection $connection, string $node, int $vmId, string $kind = 'qemu', bool $current = false): array
    {
        $this->assertGuestKind($kind);

        $path = sprintf('/nodes/%s/%s/%d/config', rawurlencode($node), $kind, $vmId);

        return $this->itemData($this->request($connection, 'GET', $path, $current ? ['query' => ['current' => 1]] : []));
    }

    public function hasPendingGuestConfiguration(ProxmoxConnection $connection, string $node, int $vmId): bool
    {
        $rows = $this->list($connection, sprintf('/nodes/%s/qemu/%d/pending', rawurlencode($node), $vmId));

        return array_any($rows, static fn (array $row): bool => isset($row['pending']) || !empty($row['delete']));
    }

    /** @return array<array-key, mixed> */
    public function updatePoolAddress(ProxmoxConnection $connection, string $node, int $vmId, string $settings, string $digest): array
    {
        return $this->item($connection, sprintf('/nodes/%s/qemu/%d/config', rawurlencode($node), $vmId), 'PUT', ['ipconfig0' => $settings, 'digest' => $digest]);
    }

    /** @return array<array-key, mixed> */
    public function importSystemDisk(ProxmoxConnection $connection, string $node, int $vmId, string $device, string $storage, string $source, string $options, string $digest): array
    {
        if (!ProxmoxDisks::validDevice('qemu', $device) || !preg_match('/^[A-Za-z][A-Za-z0-9_.-]*$/D', $storage)
            || !preg_match('~^[A-Za-z][A-Za-z0-9_.-]*:[A-Za-z0-9_./-]+$~D', $source)
            || !ProxmoxDisks::validOptions($options) || '' === $digest) {
            throw new \InvalidArgumentException('pve.error.reinstall_unavailable');
        }

        return $this->item($connection, sprintf('/nodes/%s/qemu/%d/config', rawurlencode($node), $vmId), 'POST', [
            $device => $storage.':0,import-from='.$source.$options, 'digest' => $digest,
        ]);
    }

    /** @return array<array-key, mixed> */
    public function removeUnusedDisk(ProxmoxConnection $connection, string $node, int $vmId, string $device, string $digest): array
    {
        if (!preg_match('/^unused\d+$/D', $device) || '' === $digest) {
            throw new \InvalidArgumentException('pve.error.reinstall_unavailable');
        }

        return $this->item($connection, sprintf('/nodes/%s/qemu/%d/config', rawurlencode($node), $vmId), 'PUT', ['delete' => $device, 'digest' => $digest]);
    }

    /** @return array<array-key, mixed> */
    public function attachSystemDisk(ProxmoxConnection $connection, string $node, int $vmId, string $device, string $volume, string $options, string $digest): array
    {
        if (!ProxmoxDisks::validDevice('qemu', $device) || !preg_match('~^[A-Za-z][A-Za-z0-9_.-]*:(?:'.$vmId.'/)?(?:vm|base)-'.$vmId.'-[A-Za-z0-9_.-]+$~D', $volume)
            || !ProxmoxDisks::validOptions($options) || '' === $digest) {
            throw new \InvalidArgumentException('pve.error.reinstall_rollback');
        }

        // If this changes the volume, Proxmox registers the displaced volume as unused.
        return $this->item($connection, sprintf('/nodes/%s/qemu/%d/config', rawurlencode($node), $vmId), 'POST', [$device => $volume.$options, 'digest' => $digest]);
    }

    /** A fixed read-only QGA allowlist; arbitrary guest commands are never forwarded. */
    public function guestAgentRead(ProxmoxConnection $connection, string $node, int $vmId, string $command): array
    {
        if (!in_array($command, ['info', 'get-host-name', 'get-osinfo', 'network-get-interfaces', 'get-fsinfo'], true)) {
            throw new \InvalidArgumentException('Unsupported guest agent read.');
        }
        $data = $this->request($connection, 'GET', sprintf('/nodes/%s/qemu/%d/agent/%s', rawurlencode($node), $vmId, $command), [
            'timeout' => 3, 'max_duration' => 4,
            'on_progress' => static function (int $downloaded): void { if ($downloaded > 131072) { throw new \RuntimeException('Guest agent response exceeds limit.'); } },
        ]);
        if (!is_array($data) || !is_array($data['result'] ?? null)) {
            throw new ProxmoxApiException('Invalid guest agent response.');
        }

        return JsonArray::orEmpty($data['result']);
    }

    /** @return list<array<array-key, mixed>> */
    public function guestRrd(ProxmoxConnection $connection, string $node, int $vmId, string $kind): array
    {
        $this->assertGuestKind($kind);
        $data = $this->request($connection, 'GET', sprintf('/nodes/%s/%s/%d/rrddata', rawurlencode($node), $kind, $vmId), [
            'query' => ['timeframe' => 'hour', 'cf' => 'AVERAGE'], 'timeout' => 3, 'max_duration' => 4,
            'on_progress' => static function (int $downloaded): void {
                if ($downloaded > 262144) {
                    throw new \RuntimeException('RRD response exceeds limit.');
                }
            },
        ]);
        if (!is_array($data) || !array_is_list($data)) {
            throw new ProxmoxApiException('Invalid RRD response.');
        }

        return array_values(array_filter(array_slice($data, 0, 256), 'is_array'));
    }

    /** @return array<array-key, mixed> */
    public function growDisk(ProxmoxConnection $connection, string $node, int $vmId, string $kind, string $disk, int $sizeGiB, string $digest): array
    {
        if (!ProxmoxDisks::validDevice($kind, $disk) || $sizeGiB < 1 || $sizeGiB > 65536 || '' === $digest) {
            throw new \InvalidArgumentException('Invalid disk expansion.');
        }

        return $this->item($connection, sprintf('/nodes/%s/%s/%d/resize', rawurlencode($node), $kind, $vmId), 'PUT', ['disk' => $disk, 'size' => $sizeGiB.'G', 'digest' => $digest]);
    }

    /** @return array<array-key, mixed> */
    public function addDisk(ProxmoxConnection $connection, string $node, int $vmId, string $kind, array $specification): array
    {
        // Validate again at the adapter boundary; callers cannot submit raw config fragments.
        $spec = ProxmoxExtraDisk::specification(['digest' => $specification['digest'] ?? null], $kind, [
            'confirm' => '1', 'device' => $specification['device'] ?? null, 'storage' => $specification['storage'] ?? null,
            'size_gib' => $specification['size_gib'] ?? null, 'mountpoint' => $specification['mountpoint'] ?? null, 'expected_digest' => $specification['digest'] ?? null,
        ]);
        $value = $spec['storage'].':'.$spec['size_gib'].',backup=1';
        if ('lxc' === $kind) {
            $value .= ',mp='.$spec['mountpoint'];
        }

        return $this->item($connection, sprintf('/nodes/%s/%s/%d/config', rawurlencode($node), $kind, $vmId), 'qemu' === $kind ? 'POST' : 'PUT', [$spec['device'] => $value, 'digest' => $spec['digest']]);
    }

    /** @return array<array-key, mixed> */
    public function updateResources(ProxmoxConnection $connection, string $node, int $vmId, string $kind, int $cores, int $memoryMiB, ?string $digest): array
    {
        $this->assertGuestKind($kind);
        if ($cores < 1 || $cores > 128 || $memoryMiB < 128 || $memoryMiB > 1048576) {
            throw new \InvalidArgumentException('Invalid VM resource limits.');
        }
        $body = ['cores' => $cores, 'memory' => $memoryMiB];
        if (null !== $digest) {
            $body['digest'] = $digest;
        }

        return $this->item($connection, sprintf('/nodes/%s/%s/%d/config', rawurlencode($node), $kind, $vmId), 'PUT', $body);
    }

    /**
     * @param array{cores:int, memory_mib:int, disk_gib:int}|null $rentalResources
     * @return array<array-key, mixed>
     */
    public function updateProvisioning(ProxmoxConnection $connection, string $node, int $vmId, string $hostname, string $username, array $keys, ?string $digest, bool $initialize = false, string $ipConfig = 'ip=dhcp', ?array $rentalResources = null): array
    {
        $body = ['name' => $hostname, 'ciuser' => $username, 'sshkeys' => rawurlencode(implode("\n", self::stringValues($keys)))];
        if ($initialize) {
            $body += ['agent' => 'enabled=1', 'ipconfig0' => $ipConfig];
        }
        if (null !== $rentalResources) {
            $cores = $rentalResources['cores'];
            $memory = $rentalResources['memory_mib'];
            if (!$initialize || null === $digest || '' === $digest || $cores < 1 || $cores > 256 || $memory < 128 || $memory > 1048576) {
                throw new \InvalidArgumentException('Invalid rental VM resource limits.');
            }
            $body += ['sockets' => 1, 'cores' => $cores, 'vcpus' => $cores, 'cpulimit' => 0, 'memory' => $memory, 'balloon' => 0];
        }
        if (null !== $digest) {
            $body['digest'] = $digest;
        }

        return $this->item($connection, sprintf('/nodes/%s/qemu/%d/config', rawurlencode($node), $vmId), 'PUT', $body);
    }

    /** @return array<array-key, mixed> */
    public function updateBootOrder(ProxmoxConnection $connection, string $node, int $vmId, array $devices, ?string $digest): array
    {
        $body = ['boot' => 'order='.implode(';', self::stringValues($devices))];
        if (null !== $digest) {
            $body['digest'] = $digest;
        }

        return $this->item($connection, sprintf('/nodes/%s/qemu/%d/config', rawurlencode($node), $vmId), 'PUT', $body);
    }

    /** @return array<array-key, mixed> */
    public function enableGuestAgent(ProxmoxConnection $connection, string $node, int $vmId, string $options, ?string $digest): array
    {
        $body = ['agent' => $options];
        if (null !== $digest) {
            $body['digest'] = $digest;
        }

        return $this->item($connection, sprintf('/nodes/%s/qemu/%d/config', rawurlencode($node), $vmId), 'PUT', $body);
    }

    /** @return array<array-key, mixed> */
    public function deleteSnapshot(ProxmoxConnection $connection, string $node, int $vmId, string $name, string $kind = 'qemu'): array
    {
        return $this->snapshotAction($connection, $node, $vmId, $name, $kind, false);
    }

    /** @return array<array-key, mixed> */
    public function rollbackSnapshot(ProxmoxConnection $connection, string $node, int $vmId, string $name, string $kind = 'qemu'): array
    {
        return $this->snapshotAction($connection, $node, $vmId, $name, $kind, true);
    }

    /** @return array<array-key, mixed> */
    private function snapshotAction(ProxmoxConnection $connection, string $node, int $vmId, string $name, string $kind, bool $rollback): array
    {
        $this->assertGuestKind($kind);
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,39}$/D', $name) || 'current' === $name) {
            throw new \InvalidArgumentException('Invalid snapshot name.');
        }
        $path = sprintf('/nodes/%s/%s/%d/snapshot/%s', rawurlencode($node), $kind, $vmId, rawurlencode($name));

        return $this->item($connection, $path.($rollback ? '/rollback' : ''), $rollback ? 'POST' : 'DELETE');
    }

    /** @return array<array-key, mixed> */
    public function createBackup(ProxmoxConnection $connection, string $node, int $vmId, string $storage, string $mode = 'snapshot'): array
    {
        if (!in_array($mode, ['snapshot', 'suspend', 'stop'], true)) {
            throw new \InvalidArgumentException('Invalid backup mode.');
        }

        return $this->item($connection, '/nodes/'.rawurlencode($node).'/vzdump', 'POST', ['vmid' => $vmId, 'storage' => $storage, 'mode' => $mode, 'compress' => 'zstd', 'remove' => 0]);
    }

    /** @return array<array-key, mixed> */
    public function deleteBackup(ProxmoxConnection $connection, string $node, string $storage, string $volume): array
    {
        return $this->item($connection, sprintf('/nodes/%s/storage/%s/content/%s', rawurlencode($node), rawurlencode($storage), rawurlencode($volume)), 'DELETE');
    }

    public function backupConfiguration(ProxmoxConnection $connection, string $node, string $volume): string
    {
        $path = '/nodes/'.rawurlencode($node).'/vzdump/extractconfig';
        $data = $this->request($connection, 'GET', $path, [
            'query' => ['volume' => $volume],
            'on_progress' => static function (int $bytes): void {
                if ($bytes > 262144) {
                    throw new ProxmoxApiException('Proxmox backup configuration is too large.');
                }
            },
        ]);
        if (!is_string($data) || '' === trim($data)) {
            throw new ProxmoxApiException('Proxmox returned no backup configuration.', 0, $path);
        }

        return $data;
    }

    /** @return array<array-key, mixed> */
    public function restoreBackup(ProxmoxConnection $connection, string $node, int $vmId, string $volume, string $kind = 'qemu', ?string $storage = null, bool $replace = false): array
    {
        $this->assertGuestKind($kind);
        $body = ['vmid' => $vmId, 'start' => 0, 'force' => $replace ? 1 : 0];
        if (null !== $storage && '' !== $storage) {
            $body['storage'] = $storage;
        }
        if ('qemu' === $kind) {
            $body['archive'] = $volume;
            $body['unique'] = $replace ? 0 : 1;
        } else {
            $body['ostemplate'] = $volume;
            $body['restore'] = 1;
        }

        return $this->item($connection, '/nodes/'.rawurlencode($node).'/'.$kind, 'POST', $body);
    }

    /** @return array<array-key, mixed> */
    public function cloneTemplate(ProxmoxConnection $connection, string $node, int $vmId, int $newId, string $name, string $kind = 'qemu', ?string $description = null): array
    {
        $this->assertGuestKind($kind);

        return $this->item($connection, sprintf('/nodes/%s/%s/%d/clone', rawurlencode($node), $kind, $vmId), 'POST', ['newid' => $newId, 'qemu' === $kind ? 'name' : 'hostname' => $name, 'full' => 1] + (null === $description ? [] : ['description' => $description]));
    }

    public function cloudInitBootFinished(ProxmoxConnection $connection, string $node, int $vmId, ?int $startedAt = null): bool
    {
        $data = JsonMap::strings($this->request($connection, 'GET', sprintf('/nodes/%s/qemu/%d/agent/file-read', rawurlencode($node), $vmId), [
            'query' => ['file' => '/var/lib/cloud/instance/boot-finished', 'count' => 256],
            'timeout' => 3, 'max_duration' => 4,
            'on_progress' => static function (int $bytes): void { if ($bytes > 4096) { throw new \RuntimeException('Cloud-init marker response exceeds limit.'); } },
        ]));

        $content = trim($data['content'] ?? '');
        if (null === $startedAt) {
            return '' !== $content;
        }
        if (!preg_match('/^\d+(?:\.\d+)? - ([A-Za-z]{3}, \d{1,2} [A-Za-z]{3} \d{4} \d{2}:\d{2}:\d{2} [+-]\d{4}) - v\. /D', $content, $match)) {
            return false;
        }
        $finishedAt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC2822, $match[1]);

        return false !== $finishedAt && $finishedAt->getTimestamp() >= $startedAt;
    }

    /** @return array<array-key, mixed> */
    public function setRootPasswordHash(ProxmoxConnection $connection, string $node, int $vmId, #[\SensitiveParameter] string $hash): array
    {
        $path = sprintf('/nodes/%s/qemu/%d/agent/set-user-password', rawurlencode($node), $vmId);
        $result = $this->item($connection, $path, 'POST', [
            'username' => 'root', 'password' => $hash, 'crypted' => 1,
        ]);
        // This QEMU guest-agent command has an empty successful result object.
        if (['result' => []] !== $result) {
            throw new ProxmoxApiException('Proxmox did not confirm the root password change.', 0, $path);
        }

        return ['result' => []];
    }

    public function nextVmId(ProxmoxConnection $connection, ?int $requested = null): int
    {
        $value = $this->request($connection, 'GET', '/cluster/nextid', null === $requested ? [] : ['query' => ['vmid' => $requested]]);
        if (!is_numeric($value) || (int) $value < 100 || (int) $value > 999999999) {
            throw new ProxmoxApiException('Invalid next VM ID.');
        }

        return (int) $value;
    }

    public function deleteStoppedQemuVm(ProxmoxConnection $connection, string $node, int $vmId): string
    {
        $data = JsonMap::strings($this->item($connection, sprintf('/nodes/%s/qemu/%d', rawurlencode($node), $vmId), 'DELETE'));
        $task = $data['task'] ?? '';
        if ('' === $task) {
            throw new ProxmoxApiException('No deletion task was returned.');
        }

        return $task;
    }

    public function isGuestIdFree(ProxmoxConnection $connection, int $vmId): bool
    {
        $nodes = $this->nodes($connection);
        if ([] === $nodes) {
            return false;
        }
        foreach ($nodes as $row) {
            $node = JsonMap::strings($row)['node'] ?? '';
            if ('' === $node || 'online' !== ($row['status'] ?? null)) {
                return false;
            }
            if (!$this->hasPrivilege($connection, '/nodes/'.$node, 'Sys.Audit') || [] !== $this->activeTasks($connection, $node, $vmId)) {
                return false;
            }
        }
        try {
            return $this->nextVmId($connection, $vmId) === $vmId;
        } catch (ProxmoxApiException) {
            return false;
        }
    }

    /** @return array<array-key, mixed> */
    public function mountIso(ProxmoxConnection $connection, string $node, int $vmId, string $volume, string $slot = 'ide2', ?string $digest = null): array
    {
        if (!in_array($slot, ['ide0', 'ide1', 'ide2', 'ide3'], true)) {
            throw new \InvalidArgumentException('Invalid CD/DVD slot.');
        }
        $body = [$slot => $volume.',media=cdrom'];
        if (null !== $digest) {
            $body['digest'] = $digest;
        }

        return $this->item($connection, sprintf('/nodes/%s/qemu/%d/config', rawurlencode($node), $vmId), 'PUT', $body);
    }

    /** @return array<array-key, mixed> */
    public function createVm(ProxmoxConnection $connection, string $node, array $configuration): array
    {
        return $this->item($connection, '/nodes/'.rawurlencode($node).'/qemu', 'POST', $configuration);
    }

    public function setGuestTags(ProxmoxConnection $connection, string $node, int $vmId, string $kind, string $tags, ?string $digest): void
    {
        $this->assertGuestKind($kind);
        $body = ['tags' => $tags];
        if (null !== $digest) {
            $body['digest'] = $digest;
        }

        $this->item($connection, sprintf('/nodes/%s/%s/%d/config', rawurlencode($node), $kind, $vmId), 'PUT', $body);
    }

    /** @return array<array-key, mixed> */
    public function vncProxy(ProxmoxConnection $connection, string $node, int $vmId, string $kind = 'qemu'): array
    {
        $this->assertGuestKind($kind);
        $data = $this->item($connection, sprintf('/nodes/%s/%s/%d/vncproxy', rawurlencode($node), $kind, $vmId), 'POST', ['websocket' => 1]);
        $ticket = self::optionalText($data['ticket'] ?? null);
        if ($ticket === null || strlen($ticket) < 8 || strlen($ticket) > 8192 || !is_numeric($data['port'] ?? null) || (int) $data['port'] < 1 || (int) $data['port'] > 65535) {
            throw new ProxmoxApiException('Invalid Proxmox console response.');
        }

        return ['ticket' => $ticket, 'port' => (int) $data['port']];
    }

    /** @return array<array-key, mixed> */
    public function taskStatus(ProxmoxConnection $connection, string $node, string $upid): array
    {
        return $this->item($connection, '/nodes/'.rawurlencode($node).'/tasks/'.rawurlencode($upid).'/status');
    }

    /** @return list<array<array-key, mixed>> */
    private function list(ProxmoxConnection $connection, string $path, array $query = []): array
    {
        $data = $this->request($connection, 'GET', $path, ['query' => $query]);
        if (!is_array($data) || !array_is_list($data)) {
            throw new ProxmoxApiException('Proxmox returned an invalid list response.', 0, $path);
        }

        return array_values(array_filter($data, 'is_array'));
    }

    /** @return array<array-key, mixed> */
    private function item(ProxmoxConnection $connection, string $path, string $method = 'GET', array $body = []): array
    {
        return self::itemData($this->request($connection, $method, $path, ['body' => $body]));
    }

    /** @return array<array-key, mixed> */
    private static function itemData(mixed $data): array
    {
        return is_array($data) ? $data : ['task' => $data];
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<array-key, string>
     */
    private static function stringValues(array $values): array
    {
        return array_map(static function (mixed $value): string {
            if (!is_string($value)) {
                throw new \InvalidArgumentException('Proxmox configuration values must be strings.');
            }

            return $value;
        }, $values);
    }

    private static function optionalText(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /**
     * @param array{query?: array<array-key, mixed>, body?: array<array-key, mixed>, headers?: array<array-key, mixed>, timeout?: int|float, max_duration?: int|float, on_progress?: \Closure(int): void} $options
     */
    private function request(ProxmoxConnection $connection, string $method, string $path, array $options): mixed
    {
        $url = rtrim($connection->baseUrl, '/').'/api2/json'.$path;
        $options['headers'] = ['Authorization' => 'PVEAPIToken='.$connection->tokenId.'='.$connection->tokenSecret] + JsonArray::orEmpty($options['headers'] ?? null);
        $options['verify_peer'] = $connection->verifyTls;
        $options['verify_host'] = $connection->verifyTls;
        $options['timeout'] ??= 10;
        $options['max_duration'] ??= 20;
        $options['max_redirects'] = 0;

        try {
            $response = $this->httpClient->request($method, $url, $options);
            $status = $response->getStatusCode();
            $payload = $response->toArray(false);
        } catch (TransportExceptionInterface|DecodingExceptionInterface $exception) {
            // Classify without logging raw exception text, request headers or tokens.
            $detail = strtolower($exception->getMessage());
            [$reason, $message] = match (true) {
                $exception instanceof DecodingExceptionInterface => ['invalid_json', 'Proxmox returned invalid JSON. Check the API URL and reverse proxy.'],
                (bool) preg_match('/certificate|ssl|tls/', $detail) => ['tls', 'Proxmox TLS verification failed. Check the hostname, certificate chain and trusted CA on the panel host.'],
                (bool) preg_match('/resolve|no such host|name or service/', $detail) => ['dns', 'The Proxmox hostname could not be resolved from the panel host.'],
                (bool) preg_match('/timeout|timed out/', $detail) => ['timeout', 'The Proxmox API request timed out. Check connectivity and port 8006 from the panel host.'],
                default => ['connection', 'The Proxmox API could not be reached. Check the API URL, firewall and port 8006 from the panel host.'],
            };
            $this->logger->error('Proxmox API transport failure.', ['path' => $path, 'type' => $exception::class, 'reason' => $reason, 'endpoint_host' => parse_url($connection->baseUrl, PHP_URL_HOST)]);
            throw new ProxmoxApiException($message, 0, $path);
        }

        if ($status < 200 || $status >= 300) {
            $message = self::optionalText($payload['message'] ?? null) ?? 'Proxmox API request failed.';
            $this->logger->warning('Proxmox API rejected a request.', ['path' => $path, 'status' => $status]);
            throw new ProxmoxApiException($message, $status, $path);
        }

        if (!array_key_exists('data', $payload)) {
            throw new ProxmoxApiException('Proxmox returned a malformed response.', $status, $path);
        }

        return $payload['data'];
    }

    private function assertGuestKind(string $kind): void
    {
        if (!in_array($kind, ['qemu', 'lxc'], true)) {
            throw new \InvalidArgumentException('Guest kind must be qemu or lxc.');
        }
    }
}
