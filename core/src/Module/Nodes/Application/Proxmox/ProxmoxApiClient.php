<?php

declare(strict_types=1);

namespace App\Module\Nodes\Application\Proxmox;

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

    /** @return list<array<string, mixed>> */
    public function nodes(ProxmoxConnection $connection): array
    {
        return $this->list($connection, '/nodes');
    }

    public function permissions(ProxmoxConnection $connection, string $path): array
    {
        return $this->item($connection, '/access/permissions?'.http_build_query(['path' => $path]));
    }

    public function activeTasks(ProxmoxConnection $connection, string $node, ?int $vmId): array
    {
        return $this->list($connection, '/nodes/'.rawurlencode($node).'/tasks', ['source' => 'active', 'limit' => 1] + (null === $vmId ? [] : ['vmid' => $vmId]));
    }

    /** @return list<array<string, mixed>> */
    public function clusterResources(ProxmoxConnection $connection, ?string $type = null): array
    {
        return $this->list($connection, '/cluster/resources', null === $type ? [] : ['type' => $type]);
    }

    /** @return list<array<string, mixed>> */
    public function virtualMachines(ProxmoxConnection $connection, string $node): array
    {
        return array_merge(
            $this->list($connection, sprintf('/nodes/%s/qemu', rawurlencode($node))),
            $this->list($connection, sprintf('/nodes/%s/lxc', rawurlencode($node))),
        );
    }

    /** @return array<string, mixed> */
    public function status(ProxmoxConnection $connection, string $node, int $vmId, string $kind = 'qemu'): array
    {
        $this->assertGuestKind($kind);

        return $this->item($connection, sprintf('/nodes/%s/%s/%d/status/current', rawurlencode($node), $kind, $vmId));
    }

    /** @return array<string, mixed> */
    public function changePowerState(ProxmoxConnection $connection, string $node, int $vmId, string $action, string $kind = 'qemu'): array
    {
        $this->assertGuestKind($kind);
        if (!in_array($action, ['start', 'stop', 'shutdown', 'reboot', 'reset'], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported Proxmox power action "%s".', $action));
        }

        return $this->item($connection, sprintf('/nodes/%s/%s/%d/status/%s', rawurlencode($node), $kind, $vmId, $action), 'POST');
    }

    /** @return list<array<string, mixed>> */
    public function snapshots(ProxmoxConnection $connection, string $node, int $vmId, string $kind = 'qemu'): array
    {
        $this->assertGuestKind($kind);

        return $this->list($connection, sprintf('/nodes/%s/%s/%d/snapshot', rawurlencode($node), $kind, $vmId));
    }

    /** @return array<string, mixed> */
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

    /** @return list<array<string, mixed>> */
    public function storageContent(ProxmoxConnection $connection, string $node, string $storage, ?string $content = null, ?int $vmId = null): array
    {
        return $this->list(
            $connection,
            sprintf('/nodes/%s/storage/%s/content', rawurlencode($node), rawurlencode($storage)),
            (null === $content ? [] : ['content' => $content]) + (null === $vmId ? [] : ['vmid' => $vmId]),
        );
    }

    public function nodeStatus(ProxmoxConnection $connection, string $node): array
    {
        return $this->item($connection, '/nodes/'.rawurlencode($node).'/status');
    }

    public function storages(ProxmoxConnection $connection, string $node): array
    {
        return $this->list($connection, '/nodes/'.rawurlencode($node).'/storage', ['enabled' => 1]);
    }

    public function networks(ProxmoxConnection $connection, string $node): array
    {
        return $this->list($connection, '/nodes/'.rawurlencode($node).'/network', ['type' => 'bridge']);
    }

    public function guestConfig(ProxmoxConnection $connection, string $node, int $vmId, string $kind = 'qemu'): array
    {
        $this->assertGuestKind($kind);

        return $this->item($connection, sprintf('/nodes/%s/%s/%d/config', rawurlencode($node), $kind, $vmId));
    }

    /** A fixed read-only QGA allowlist; arbitrary guest commands are never forwarded. */
    public function guestAgentRead(ProxmoxConnection $connection, string $node, int $vmId, string $command): array
    {
        if (!in_array($command, ['info', 'get-host-name', 'get-osinfo', 'network-get-interfaces'], true)) {
            throw new \InvalidArgumentException('Unsupported guest agent read.');
        }
        $data = $this->request($connection, 'GET', sprintf('/nodes/%s/qemu/%d/agent/%s', rawurlencode($node), $vmId, $command), [
            'timeout' => 3, 'max_duration' => 4,
            'on_progress' => static function (int $downloaded): void { if ($downloaded > 131072) { throw new \RuntimeException('Guest agent response exceeds limit.'); } },
        ]);
        if (!is_array($data) || !is_array($data['result'] ?? null)) {
            throw new ProxmoxApiException('Invalid guest agent response.');
        }

        return $data['result'];
    }

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

        return array_slice($data, 0, 256);
    }

    public function growDisk(ProxmoxConnection $connection, string $node, int $vmId, string $kind, string $disk, int $sizeGiB, string $digest): array
    {
        if (!ProxmoxDisks::validDevice($kind, $disk) || $sizeGiB < 1 || $sizeGiB > 65536 || '' === $digest) {
            throw new \InvalidArgumentException('Invalid disk expansion.');
        }

        return $this->item($connection, sprintf('/nodes/%s/%s/%d/resize', rawurlencode($node), $kind, $vmId), 'PUT', ['disk' => $disk, 'size' => $sizeGiB.'G', 'digest' => $digest]);
    }

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

    public function updateProvisioning(ProxmoxConnection $connection, string $node, int $vmId, string $hostname, string $username, array $keys, ?string $digest): array
    {
        $body = ['name' => $hostname, 'ciuser' => $username, 'sshkeys' => rawurlencode(implode("\n", $keys))];
        if (null !== $digest) {
            $body['digest'] = $digest;
        }

        return $this->item($connection, sprintf('/nodes/%s/qemu/%d/config', rawurlencode($node), $vmId), 'PUT', $body);
    }

    public function updateBootOrder(ProxmoxConnection $connection, string $node, int $vmId, array $devices, ?string $digest): array
    {
        $body = ['boot' => 'order='.implode(';', $devices)];
        if (null !== $digest) {
            $body['digest'] = $digest;
        }

        return $this->item($connection, sprintf('/nodes/%s/qemu/%d/config', rawurlencode($node), $vmId), 'PUT', $body);
    }

    public function enableGuestAgent(ProxmoxConnection $connection, string $node, int $vmId, string $options, ?string $digest): array
    {
        $body = ['agent' => $options];
        if (null !== $digest) {
            $body['digest'] = $digest;
        }

        return $this->item($connection, sprintf('/nodes/%s/qemu/%d/config', rawurlencode($node), $vmId), 'PUT', $body);
    }

    public function deleteSnapshot(ProxmoxConnection $connection, string $node, int $vmId, string $name, string $kind = 'qemu'): array
    {
        return $this->snapshotAction($connection, $node, $vmId, $name, $kind, false);
    }

    public function rollbackSnapshot(ProxmoxConnection $connection, string $node, int $vmId, string $name, string $kind = 'qemu'): array
    {
        return $this->snapshotAction($connection, $node, $vmId, $name, $kind, true);
    }

    private function snapshotAction(ProxmoxConnection $connection, string $node, int $vmId, string $name, string $kind, bool $rollback): array
    {
        $this->assertGuestKind($kind);
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,39}$/D', $name) || 'current' === $name) {
            throw new \InvalidArgumentException('Invalid snapshot name.');
        }
        $path = sprintf('/nodes/%s/%s/%d/snapshot/%s', rawurlencode($node), $kind, $vmId, rawurlencode($name));

        return $this->item($connection, $path.($rollback ? '/rollback' : ''), $rollback ? 'POST' : 'DELETE');
    }

    public function createBackup(ProxmoxConnection $connection, string $node, int $vmId, string $storage, string $mode = 'snapshot'): array
    {
        if (!in_array($mode, ['snapshot', 'suspend', 'stop'], true)) {
            throw new \InvalidArgumentException('Invalid backup mode.');
        }

        return $this->item($connection, '/nodes/'.rawurlencode($node).'/vzdump', 'POST', ['vmid' => $vmId, 'storage' => $storage, 'mode' => $mode, 'compress' => 'zstd', 'remove' => 0]);
    }

    public function deleteBackup(ProxmoxConnection $connection, string $node, string $storage, string $volume): array
    {
        return $this->item($connection, sprintf('/nodes/%s/storage/%s/content/%s', rawurlencode($node), rawurlencode($storage), rawurlencode($volume)), 'DELETE');
    }

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

    public function cloneTemplate(ProxmoxConnection $connection, string $node, int $vmId, int $newId, string $name, string $kind = 'qemu'): array
    {
        $this->assertGuestKind($kind);

        return $this->item($connection, sprintf('/nodes/%s/%s/%d/clone', rawurlencode($node), $kind, $vmId), 'POST', ['newid' => $newId, 'qemu' === $kind ? 'name' : 'hostname' => $name, 'full' => 1]);
    }

    public function nextVmId(ProxmoxConnection $connection): int
    {
        $value = $this->request($connection, 'GET', '/cluster/nextid', []);
        if (!is_numeric($value) || (int) $value < 100) {
            throw new ProxmoxApiException('Invalid next VM ID.');
        }

        return (int) $value;
    }

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

    public function createVm(ProxmoxConnection $connection, string $node, array $configuration): array
    {
        return $this->item($connection, '/nodes/'.rawurlencode($node).'/qemu', 'POST', $configuration);
    }

    public function setGuestTags(ProxmoxConnection $connection, string $node, int $vmId, string $kind, string $tags, ?string $digest): array
    {
        $this->assertGuestKind($kind);
        $body = ['tags' => $tags];
        if (null !== $digest) {
            $body['digest'] = $digest;
        }

        return $this->item($connection, sprintf('/nodes/%s/%s/%d/config', rawurlencode($node), $kind, $vmId), 'PUT', $body);
    }

    public function vncProxy(ProxmoxConnection $connection, string $node, int $vmId, string $kind = 'qemu'): array
    {
        $this->assertGuestKind($kind);
        $data = $this->item($connection, sprintf('/nodes/%s/%s/%d/vncproxy', rawurlencode($node), $kind, $vmId), 'POST', ['websocket' => 1]);
        if (!is_string($data['ticket'] ?? null) || strlen($data['ticket']) < 8 || strlen($data['ticket']) > 8192 || !is_numeric($data['port'] ?? null) || (int) $data['port'] < 1 || (int) $data['port'] > 65535) {
            throw new ProxmoxApiException('Invalid Proxmox console response.');
        }

        return ['ticket' => $data['ticket'], 'port' => (int) $data['port']];
    }

    public function taskStatus(ProxmoxConnection $connection, string $node, string $upid): array
    {
        return $this->item($connection, '/nodes/'.rawurlencode($node).'/tasks/'.rawurlencode($upid).'/status');
    }

    public function taskLog(ProxmoxConnection $connection, string $node, string $upid): array
    {
        return $this->list($connection, '/nodes/'.rawurlencode($node).'/tasks/'.rawurlencode($upid).'/log', ['limit' => 50]);
    }

    /** @return list<array<string, mixed>> */
    private function list(ProxmoxConnection $connection, string $path, array $query = []): array
    {
        $data = $this->request($connection, 'GET', $path, ['query' => $query]);
        if (!is_array($data) || !array_is_list($data)) {
            throw new ProxmoxApiException('Proxmox returned an invalid list response.', 0, $path);
        }

        return array_values(array_filter($data, 'is_array'));
    }

    /** @return array<string, mixed> */
    private function item(ProxmoxConnection $connection, string $path, string $method = 'GET', array $body = []): array
    {
        $data = $this->request($connection, $method, $path, ['body' => $body]);

        return is_array($data) ? $data : ['task' => $data];
    }

    private function request(ProxmoxConnection $connection, string $method, string $path, array $options): mixed
    {
        $url = rtrim($connection->baseUrl, '/').'/api2/json'.$path;
        $options['headers']['Authorization'] = 'PVEAPIToken='.$connection->tokenId.'='.$connection->tokenSecret;
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
            $message = is_string($payload['message'] ?? null) ? $payload['message'] : 'Proxmox API request failed.';
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
