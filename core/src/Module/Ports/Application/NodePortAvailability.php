<?php

declare(strict_types=1);

namespace App\Module\Ports\Application;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Gameserver\Infrastructure\Client\AgentGameServerClient;

final class NodePortAvailability
{
    public function __construct(private readonly AgentGameServerClient $client)
    {
    }

    public function blockedPorts(Agent $node, array $ports): array
    {
        $checks = [];
        foreach (array_unique($ports) as $port) {
            foreach (['tcp', 'udp'] as $proto) {
                $checks[] = ['proto' => $proto, 'port' => $port];
            }
        }
        $seen = [];
        $blocked = [];
        try {
            $results = [];
            foreach (array_chunk($checks, 256) as $batch) {
                $results = array_merge($results, $this->client->checkFreePortsForNode($node, $batch));
            }
        } catch (\Throwable $error) {
            throw new PortCheckUnavailable('Portprüfung auf dem Node fehlgeschlagen. Es wurden keine Ports vergeben.', previous: $error);
        }
        if (count($results) !== count($checks)) {
            throw new PortCheckUnavailable('Unvollständige Antwort der Node-Portprüfung.');
        }
        foreach ($results as $result) {
            if (!is_array($result) || !is_int($result['port'] ?? null) || !in_array($result['proto'] ?? null, ['tcp', 'udp'], true) || !is_bool($result['free'] ?? null) || !empty($result['error'])) {
                throw new PortCheckUnavailable('Unvollständige oder ungültige Antwort der Node-Portprüfung.');
            }
            $key = $result['proto'].':'.$result['port'];
            if (isset($seen[$key])) {
                throw new PortCheckUnavailable('Doppelte Antwort der Node-Portprüfung.');
            }
            $seen[$key] = true;
            if (!$result['free']) {
                $blocked[$result['port']] = true;
            }
        }
        foreach ($checks as $check) {
            if (!isset($seen[$check['proto'].':'.$check['port']])) {
                throw new PortCheckUnavailable('Unvollständige Antwort der Node-Portprüfung.');
            }
        }

        return array_keys($blocked);
    }
}
