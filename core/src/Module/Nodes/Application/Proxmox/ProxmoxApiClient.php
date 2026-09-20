<?php

declare(strict_types=1);

namespace App\Module\Nodes\Application\Proxmox;

use App\Module\Nodes\Domain\Proxmox\ProxmoxApiException;
use App\Module\Nodes\Domain\Proxmox\ProxmoxConnection;
use Psr\Log\LoggerInterface;
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
        if ('' === $name || 1 !== preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,39}$/', $name)) {
            throw new \InvalidArgumentException('Snapshot names must contain 1-40 letters, numbers, underscores, or hyphens.');
        }

        $body = ['snapname' => $name];
        if (null !== $description && '' !== trim($description)) {
            $body['description'] = trim($description);
        }

        return $this->item($connection, sprintf('/nodes/%s/%s/%d/snapshot', rawurlencode($node), $kind, $vmId), 'POST', $body);
    }

    /** @return list<array<string, mixed>> */
    public function storageContent(ProxmoxConnection $connection, string $node, string $storage, ?string $content = null): array
    {
        return $this->list(
            $connection,
            sprintf('/nodes/%s/storage/%s/content', rawurlencode($node), rawurlencode($storage)),
            null === $content ? [] : ['content' => $content],
        );
    }

    /** @return list<array<string, mixed>> */
    private function list(ProxmoxConnection $connection, string $path, array $query = []): array
    {
        $data = $this->request($connection, 'GET', $path, ['query' => $query]);
        if (!array_is_list($data)) {
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

    /** @return mixed */
    private function request(ProxmoxConnection $connection, string $method, string $path, array $options): mixed
    {
        $url = rtrim($connection->baseUrl, '/').'/api2/json'.$path;
        $options['headers']['Authorization'] = 'PVEAPIToken='.$connection->tokenId.'='.$connection->tokenSecret;
        $options['verify_peer'] = $connection->verifyTls;
        $options['verify_host'] = $connection->verifyTls;

        try {
            $response = $this->httpClient->request($method, $url, $options);
            $status = $response->getStatusCode();
            $payload = $response->toArray(false);
        } catch (TransportExceptionInterface $exception) {
            $this->logger->error('Proxmox API transport failure.', ['path' => $path, 'exception' => $exception]);
            throw new ProxmoxApiException('The Proxmox API is unavailable.', 0, $path, $exception);
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
