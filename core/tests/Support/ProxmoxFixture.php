<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Nodes\Application\Proxmox\ProxmoxApiClient;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Stateful PVE contract fixture: no network or real infrastructure mutations. */
final class ProxmoxFixture
{
    public array $requests = [];
    public bool $loseWriteResponse = false;
    public int $writeFailureStatus = 503;
    public bool $auditPermission = true;
    public array $activeJobs = [];
    public int $configReadError = 0;
    public array $configs = [];
    public array $configTags = [];
    public int $guestAgentError = 0;
    public bool $guestNetworkError = false;
    public array $guestInterfaces = [['name' => 'eth0', 'hardware-address' => '52:54:00:12:34:56', 'ip-addresses' => [['ip-address-type' => 'ipv4', 'ip-address' => '192.0.2.10', 'prefix' => 24], ['ip-address-type' => 'ipv6', 'ip-address' => '2001:db8::10', 'prefix' => 64]]]];
    public array $guestAgentInfo = ['version' => '9.2.0', 'supported_commands' => [['name' => 'guest-get-host-name', 'enabled' => true], ['name' => 'guest-get-osinfo', 'enabled' => true], ['name' => 'guest-network-get-interfaces', 'enabled' => true]]];
    public bool $rrdFail = false;
    public ?array $rrdRows = null;
    public bool $complete = false;
    public bool $fail = false;
    public bool $failWrites = false;
    public array $nodes = [['node' => 'pve-a', 'type' => 'node', 'status' => 'online', 'cpu' => 0.2, 'mem' => 4294967296, 'maxmem' => 17179869184], ['node' => 'pve-b', 'type' => 'node', 'status' => 'online']];
    public array $guests = [
        ['vmid' => 101, 'type' => 'qemu', 'node' => 'pve-a', 'name' => 'web', 'status' => 'stopped', 'cpu' => 0.1, 'mem' => 1073741824, 'maxmem' => 2147483648, 'maxdisk' => 34359738368, 'tags' => 'easywi-owned-0123456789abcdef'],
        ['vmid' => 102, 'type' => 'lxc', 'node' => 'pve-b', 'name' => 'cache', 'status' => 'running', 'tags' => 'easywi-owned-fedcba9876543210'],
        ['vmid' => 900, 'type' => 'qemu', 'node' => 'pve-a', 'name' => 'debian-template', 'status' => 'stopped', 'template' => 1],
    ];
    public array $snapshots = [['name' => 'current'], ['name' => 'before-update', 'description' => 'Safe checkpoint']];

    public function client(): ProxmoxApiClient
    {
        return new ProxmoxApiClient(new MockHttpClient($this->respond(...)), new NullLogger());
    }

    private function respond(string $method, string $url, array $options): MockResponse
    {
        $path = rawurldecode((string) parse_url($url, PHP_URL_PATH));
        $path = substr($path, strlen('/api2/json'));
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $body = [];
        if (is_string($options['body'] ?? null)) {
            parse_str($options['body'], $body);
        }
        $this->requests[] = ['method' => $method, 'path' => $path, 'query' => $query, 'body' => $body];
        if ($this->loseWriteResponse && 'GET' !== $method) {
            throw new \Symfony\Component\HttpClient\Exception\TransportException('Synthetic lost write response');
        }
        if ($this->fail || ($this->failWrites && 'GET' !== $method)) {
            return new MockResponse('{"data":null,"message":"private-upstream-error"}', ['http_code' => $this->fail ? 503 : $this->writeFailureStatus]);
        }
        $nodes = $this->nodes;
        $data = null;
        if ('/access/permissions' === $path) {
            $data = [$query['path'] => ['Sys.Audit' => (int) $this->auditPermission]];
        } elseif (preg_match('~^/nodes/[^/]+/tasks$~', $path)) {
            $data = $this->activeJobs;
        } elseif ('/cluster/resources' === $path) {
            $data = [...$nodes, ...$this->guests];
        } elseif ('/cluster/nextid' === $path) {
            $data = '1001';
        } elseif ('/nodes' === $path) {
            $data = $nodes;
        } elseif (preg_match('~^/nodes/[^/]+/storage$~', $path)) {
            $data = [['storage' => 'local', 'active' => 1, 'content' => 'iso,vztmpl,backup'], ['storage' => 'local-lvm', 'active' => 1, 'content' => 'images,rootdir'], ['storage' => 'disabled', 'active' => 0, 'content' => 'backup']];
        } elseif (preg_match('~^/nodes/[^/]+/network$~', $path)) {
            $data = [['iface' => 'vmbr0', 'type' => 'bridge', 'active' => 1]];
        } elseif (preg_match('~^/nodes/[^/]+/storage/([^/]+)/content$~', $path, $match)) {
            $data = ($query['content'] ?? '') === 'iso' ? [['volid' => $match[1].':iso/debian.iso', 'content' => 'iso']] : [['volid' => $match[1].':backup/vzdump-qemu-101-2026_11_12.vma.zst', 'vmid' => 101, 'content' => 'backup'], ['volid' => $match[1].':backup/vzdump-lxc-102-2026_11_12.tar.zst', 'vmid' => 102, 'content' => 'backup'], ['volid' => $match[1].':backup/vzdump-qemu-999-2026_11_12.vma.zst', 'vmid' => 999, 'content' => 'backup']];
        } elseif (preg_match('~^/nodes/[^/]+/tasks/.+/status$~', $path)) {
            $data = $this->complete ? ['status' => 'stopped', 'exitstatus' => 'OK'] : ['status' => 'running'];
        } elseif (preg_match('~^/nodes/[^/]+/(qemu|lxc)/(\d+)/rrddata$~', $path)) {
            if ($this->rrdFail) {
                return new MockResponse('{"data":null,"message":"private RRD failure"}', ['http_code' => 403]);
            }
            $data = $this->rrdRows ?? [['time' => time() - 65], ['time' => time() - 5, 'netin' => 1048576, 'netout' => 524288, 'diskread' => 2097152, 'diskwrite' => 262144]];
        } elseif (preg_match('~^/nodes/[^/]+/(qemu|lxc)/(\d+)/status/current$~', $path, $match)) {
            $data = $this->findGuest((int) $match[2]);
        } elseif (preg_match('~^/nodes/[^/]+/(qemu|lxc)/(\d+)/snapshot$~', $path, $match)) {
            if ('GET' === $method) {
                $data = $this->snapshots;
            } else {
                $this->snapshots[] = ['name' => $body['snapname'], 'description' => $body['description'] ?? ''];
                $data = 'UPID:pve-a:snapshot';
            }
        } elseif (preg_match('~^/nodes/[^/]+/(qemu|lxc)/(\d+)/vncproxy$~', $path)) {
            $data = ['port' => '5900', 'ticket' => 'synthetic-proxmox-vnc-ticket'];
        } elseif (preg_match('~^/nodes/[^/]+/qemu/(\d+)/agent/(info|get-host-name|get-osinfo|network-get-interfaces)$~', $path, $match)) {
            if ($this->guestNetworkError && 'network-get-interfaces' === $match[2]) {
                return new MockResponse('{"data":null,"message":"private network failure"}', ['http_code' => 500]);
            }
            if ($this->guestAgentError) {
                return new MockResponse('{"data":null,"message":"private guest failure"}', ['http_code' => $this->guestAgentError]);
            }
            $data = ['result' => match ($match[2]) {
                'info' => $this->guestAgentInfo, 'network-get-interfaces' => $this->guestInterfaces, 'get-host-name' => ['host-name' => 'actual-web', 'private' => '/secret'], default => ['pretty-name' => 'Debian GNU/Linux 13'],
            }];
        } elseif (preg_match('~^/nodes/[^/]+/(qemu|lxc)/(\d+)/resize$~', $path, $match)) {
            $device = $body['disk'];
            $config = $this->configs[(int) $match[2]][$device] ?? '';
            $this->configs[(int) $match[2]][$device] = preg_replace('/,size=[^,]+/', ',size='.$body['size'], $config);
            $data = null;
        } elseif (preg_match('~^/nodes/[^/]+/(qemu|lxc)/(\d+)/config$~', $path, $match)) {
            if ('GET' === $method && $this->configReadError) {
                return new MockResponse('{"data":null,"message":"private config read failure"}', ['http_code' => $this->configReadError]);
            }
            $data = 'GET' === $method ? (['tags' => $this->configTags[(int) $match[2]] ?? $this->findGuest((int) $match[2])['tags'] ?? '', 'digest' => 'fixture-digest'] + ($this->configs[(int) $match[2]] ?? [])) : null;
            if (in_array($method, ['PUT', 'POST'], true)) {
                $this->configs[(int) $match[2]] = array_replace($this->configs[(int) $match[2]] ?? [], array_intersect_key($body, array_flip(['cores', 'memory', 'name', 'ciuser', 'sshkeys', 'boot', 'agent'])));
                foreach ($body as $device => $value) {
                    if (\App\Module\Nodes\Application\Proxmox\ProxmoxDisks::validDevice($match[1], $device) && preg_match('~^([A-Za-z][A-Za-z0-9_-]*):(\d+)(,backup=1(?:,mp=/[^,]+)?)$~D', (string) $value, $disk)) {
                        $this->configs[(int) $match[2]][$device] = $disk[1].':vm-'.$match[2].'-disk-1,size='.$disk[2].'G'.$disk[3];
                    }
                }
                if ('POST' === $method && 'qemu' === $match[1]) {
                    $data = 'UPID:pve-a:config';
                }
            }
            if (isset($body['tags'])) {
                foreach ($this->guests as &$guest) {
                    if ((int) $guest['vmid'] === (int) $match[2]) {
                        $guest['tags'] = $body['tags'];
                    }
                } unset($guest);
            }
        } elseif ('GET' !== $method) {
            $data = 'UPID:pve-a:'.count($this->requests);
        } else {
            throw new \LogicException('Unexpected PVE fixture request: '.$method.' '.$path);
        }

        return new MockResponse(json_encode(['data' => $data], JSON_THROW_ON_ERROR));
    }

    private function findGuest(int $vmId): array
    {
        foreach ($this->guests as $guest) {
            if ((int) $guest['vmid'] === $vmId) {
                return $guest;
            }
        }

        return [];
    }

    public function writes(): array
    {
        return array_values(array_filter($this->requests, static fn (array $r): bool => 'GET' !== $r['method']));
    }
}
