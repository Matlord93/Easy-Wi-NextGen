<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application\Query;

use App\Module\Core\Domain\Entity\Instance;

final class InstanceQueryHostResolver
{
    /**
     * @return array{host: ?string, source: 'bind_ip'|'node_ip'|'loopback'|'', network_mode: string, missing_fields: list<string>}
     */
    public function resolve(Instance $instance): array
    {
        $setupVars = $instance->getSetupVars();
        $networkMode = $this->resolveNetworkMode($instance, $setupVars);

        foreach (['BIND_IP', 'bind_ip', 'CONNECT_IP', 'connect_ip'] as $setupVarKey) {
            $candidate = trim((string) ($setupVars[$setupVarKey] ?? ''));
            $resolved = $this->resolveHost($candidate);
            if ($resolved !== null) {
                return [
                    'host' => $resolved,
                    'source' => 'bind_ip',
                    'network_mode' => $networkMode,
                    'missing_fields' => [],
                ];
            }
        }

        foreach (['PUBLIC_IP', 'public_ip', 'HOST_IP', 'host_ip', 'SERVER_IP', 'server_ip', 'IP', 'ip'] as $setupVarKey) {
            $candidate = trim((string) ($setupVars[$setupVarKey] ?? ''));
            $resolved = $this->resolveHost($candidate);
            if ($resolved !== null) {
                return [
                    'host' => $resolved,
                    'source' => 'bind_ip',
                    'network_mode' => $networkMode,
                    'missing_fields' => [],
                ];
            }
        }

        $node = $instance->getNode();
        $metadata = $node->getMetadata();
        $metadata = is_array($metadata) ? $metadata : [];

        foreach (['primary_ip', 'node_ip', 'ip', 'public_ip', 'external_ip', 'query_ip', 'advertise_ip', 'advertised_ip', 'host', 'hostname', 'fqdn', 'domain', 'dns_name'] as $metadataKey) {
            $candidate = trim((string) ($metadata[$metadataKey] ?? ''));
            $resolved = $this->resolveHost($candidate);
            if ($resolved !== null) {
                return [
                    'host' => $resolved,
                    'source' => 'node_ip',
                    'network_mode' => $networkMode,
                    'missing_fields' => [],
                ];
            }
        }

        $heartbeatIp = trim((string) $node->getLastHeartbeatIp());
        $resolvedHeartbeat = $this->resolveHost($heartbeatIp);
        if ($resolvedHeartbeat !== null) {
            return [
                'host' => $resolvedHeartbeat,
                'source' => 'node_ip',
                'network_mode' => $networkMode,
                'missing_fields' => [],
            ];
        }

        $serviceBaseUrl = trim((string) $node->getServiceBaseUrl());
        if ($serviceBaseUrl !== '') {
            $parsedHost = parse_url($serviceBaseUrl, PHP_URL_HOST);
            if (is_string($parsedHost)) {
                $resolved = $this->resolveHost($parsedHost);
                if ($resolved !== null) {
                    return [
                        'host' => $resolved,
                        'source' => 'node_ip',
                        'network_mode' => $networkMode,
                        'missing_fields' => [],
                    ];
                }
            }
        }

        if ($networkMode === 'host') {
            return [
                'host' => '127.0.0.1',
                'source' => 'loopback',
                'network_mode' => $networkMode,
                'missing_fields' => [],
            ];
        }

        return [
            'host' => null,
            'source' => '',
            'network_mode' => $networkMode,
            'missing_fields' => ['instance.bind_ip|connect_ip', 'node.primary_ip|node_ip'],
        ];
    }

    private function resolveHost(string $candidate): ?string
    {
        $host = trim($candidate);
        if ($host === '' || $this->isLoopbackHost($host)) {
            return null;
        }

        if (filter_var($host, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4) !== false) {
            return $host;
        }

        if (filter_var($host, \FILTER_VALIDATE_DOMAIN, \FILTER_FLAG_HOSTNAME) !== false) {
            return $host;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $setupVars
     */
    private function resolveNetworkMode(Instance $instance, array $setupVars): string
    {
        $rawMode = strtolower(trim((string) ($setupVars['NETWORK_MODE'] ?? $setupVars['network_mode'] ?? '')));
        if ($rawMode === 'host' || $rawMode === 'isolated') {
            return $rawMode;
        }

        $requirements = $instance->getTemplate()->getRequirements();
        $queryConfigRaw = $requirements['query'] ?? null;
        $queryConfig = is_array($queryConfigRaw) ? $queryConfigRaw : [];
        $shareHostNetwork = $queryConfig['share_host_network'] ?? $queryConfig['local_only'] ?? null;

        $shareHost = is_bool($shareHostNetwork)
            ? $shareHostNetwork
            : in_array(strtolower(trim((string) $shareHostNetwork)), ['1', 'true', 'yes'], true);

        return $shareHost ? 'host' : 'isolated';
    }

    private function isLoopbackHost(string $host): bool
    {
        $normalized = strtolower(trim($host));

        return $normalized === 'localhost'
            || $normalized === '::1'
            || str_starts_with($normalized, '127.');
    }
}
