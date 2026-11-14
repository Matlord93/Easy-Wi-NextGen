<?php

declare(strict_types=1);

namespace App\Module\Nodes\Application\Proxmox;

use App\Infrastructure\Config\JsonArray;
use App\Module\Nodes\Application\VmGuestAgentReport;
use App\Module\Nodes\Application\VmGuestNetworkReport;
use App\Module\Nodes\Domain\Proxmox\ProxmoxApiException;
use App\Module\Nodes\Domain\Proxmox\ProxmoxConnection;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final readonly class ProxmoxGuestAgent
{
    public function __construct(private ProxmoxApiClient $api, private CacheInterface $cache)
    {
    }

    /** Called only after fresh ownership, identity, running-state and config checks. */
    public function probe(ProxmoxConnection $connection, string $node, int $vmId, string $digest): array
    {
        $key = 'vm_guest_agent.network_v1.'.hash('sha256', json_encode([$connection->baseUrl, $connection->tokenId, hash('sha256', $connection->tokenSecret), $connection->verifyTls, $node, $vmId, $digest]));

        return $this->cache->get($key, function (ItemInterface $item) use ($connection, $node, $vmId): array {
            $item->expiresAfter(10);
            try {
                $info = $this->api->guestAgentRead($connection, $node, $vmId, 'info');
            } catch (ProxmoxApiException $error) {
                return VmGuestAgentReport::observed(in_array($error->statusCode, [401, 403], true) ? 'forbidden' : ($error->statusCode >= 500 ? 'unreachable' : 'unavailable'));
            }
            $version = VmGuestAgentReport::text($info['version'] ?? null, 128);
            if (null === $version) {
                return VmGuestAgentReport::observed('invalid_response');
            }
            $fields = ['version' => $version];
            $commands = array_slice(JsonArray::orEmpty($info['supported_commands'] ?? null), 0, 256);
            $fields['network'] = VmGuestNetworkReport::empty('unsupported');
            $networkSupported = array_filter($commands, static fn ($row): bool => is_array($row) && ($row['name'] ?? null) === 'guest-network-get-interfaces' && ($row['enabled'] ?? null) === true);
            if ($networkSupported) {
                try {
                    $fields['network'] = VmGuestNetworkReport::fromQga($this->api->guestAgentRead($connection, $node, $vmId, 'network-get-interfaces'));
                } catch (ProxmoxApiException) {
                    $fields['network'] = VmGuestNetworkReport::empty('unavailable');
                }
            }
            foreach (['get-host-name' => ['hostname', 'host-name'], 'get-osinfo' => ['os', 'pretty-name']] as $command => [$field, $source]) {
                $supported = array_filter($commands, static fn ($row): bool => is_array($row) && ($row['name'] ?? null) === 'guest-'.$command && ($row['enabled'] ?? null) === true);
                if (!$supported) {
                    continue;
                }
                try {
                    $fields[$field] = $this->api->guestAgentRead($connection, $node, $vmId, $command)[$source] ?? null;
                } catch (ProxmoxApiException) { /* Preserve the successful info observation. */
                }
            }

            return VmGuestAgentReport::observed('reachable', $fields);
        });
    }
}
