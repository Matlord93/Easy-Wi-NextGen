<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application\Query;

use App\Module\Core\Domain\Entity\Instance;
use App\Module\Ports\Domain\Entity\PortBlock;

final class InstanceQueryResolver
{
    private const DEFAULT_TIMEOUT_MS = 4000;
    private const QUERY_PORT_BEHAVIOR_SAME_AS_GAME_PORT = 'same_as_game_port';
    private const QUERY_PORT_BEHAVIOR_EXPLICIT = 'explicit';
    private const QUERY_PORT_BEHAVIOR_PLUS_ONE = 'plus_one';

    public function __construct(
        private readonly InstanceQueryHostResolver $hostResolver = new InstanceQueryHostResolver(),
    ) {
    }

    /**
     * @throws InvalidInstanceQueryConfiguration
     */
    public function resolve(Instance $instance, ?PortBlock $portBlock): InstanceQuerySpec
    {
        $template = $instance->getTemplate();
        $requirements = $template->getRequirements();
        $queryConfigRaw = $requirements['query'] ?? null;
        $queryConfig = is_array($queryConfigRaw) ? $queryConfigRaw : [];

        $type = $this->resolveQueryType($instance, $requirements, $queryConfigRaw, $queryConfig);
        if ($type === null) {
            return InstanceQuerySpec::unsupported();
        }

        $hostResolution = $this->hostResolver->resolve($instance);
        $host = trim((string) ($hostResolution['host'] ?? ''));
        if ($host === '') {
            $instanceId = $instance->getId();
            $missingFields = implode(', ', $hostResolution['missing_fields']);
            throw new InvalidInstanceQueryConfiguration(sprintf('Query host is missing for instance %s (missing fields: %s).', $instanceId !== null ? (string) $instanceId : 'unknown', $missingFields));
        }

        $portResolution = $this->resolveQueryPort($instance, $portBlock, $type, $queryConfig);
        $port = $portResolution['port'];
        if ($port === null || $port < 1 || $port > 65535) {
            throw new InvalidInstanceQueryConfiguration('Query port is invalid.');
        }

        $timeoutMs = (int) ($queryConfig['timeout_ms'] ?? self::DEFAULT_TIMEOUT_MS);
        if ($timeoutMs <= 0) {
            $timeoutMs = self::DEFAULT_TIMEOUT_MS;
        }

        return new InstanceQuerySpec(
            true,
            $type,
            $host,
            $port,
            $timeoutMs,
            [
                'via' => strtolower(trim((string) ($queryConfig['via'] ?? $queryConfig['mode'] ?? 'agent'))),
                'resolved_host_source' => $hostResolution['source'],
                'resolved_port_source' => $portResolution['source'],
                'network_mode' => $hostResolution['network_mode'],
            ],
        );
    }

    /**
     * @param array<string, mixed> $requirements
     * @param array<string, mixed> $queryConfig
     */
    private function resolveQueryType(Instance $instance, array $requirements, mixed $queryConfigRaw, array $queryConfig): ?string
    {
        $declaredType = $this->normalizeQueryType((string) ($queryConfig['type'] ?? ''));
        if ($declaredType === '') {
            $declaredType = $this->normalizeQueryType((string) ($queryConfig['query_type'] ?? $queryConfig['engine'] ?? $queryConfig['driver'] ?? $queryConfig['adapter'] ?? $queryConfig['system'] ?? $requirements['query_type'] ?? ''));
        }
        if ($declaredType === '' && is_string($queryConfigRaw)) {
            $declaredType = $this->normalizeQueryType($queryConfigRaw);
        }

        $queryDisabled = $queryConfigRaw === false
            || ($queryConfig['enabled'] ?? true) === false
            || ($queryConfig['active'] ?? true) === false
            || in_array($declaredType, ['none', 'off', 'disabled', 'disable', 'false'], true);
        if ($queryDisabled) {
            return null;
        }

        if ($declaredType !== '') {
            return $declaredType;
        }

        $gameKey = strtolower(trim($instance->getTemplate()->getGameKey()));
        if (str_contains($gameKey, 'minecraft_bedrock') || str_starts_with($gameKey, 'bedrock')) {
            return 'minecraft_bedrock';
        }
        if (str_contains($gameKey, 'minecraft')) {
            return 'minecraft_java';
        }

        if ($instance->getTemplate()->getSteamAppId() !== null) {
            return 'steam_a2s';
        }

        return null;
    }

    private function normalizeQueryType(string $rawType): string
    {
        $declaredType = strtolower(trim($rawType));

        return match ($declaredType) {
            'source', 'source_engine', 'source-engine', 'source1', 'source_1', 'source-1', 'goldsrc', 'steam', 'valve' => 'steam_a2s',
            'a2s', 'steam_a2s' => 'steam_a2s',
            'minecraft', 'minecraft_java', 'java' => 'minecraft_java',
            'minecraft_bedrock', 'bedrock', 'mcpe' => 'minecraft_bedrock',
            default => $declaredType,
        };
    }

    /**
     * @param array<string, mixed> $queryConfig
     */
    private function resolveQueryPort(Instance $instance, ?PortBlock $portBlock, string $queryType, array $queryConfig): array
    {
        if (is_numeric($queryConfig['port'] ?? null)) {
            return ['port' => (int) $queryConfig['port'], 'source' => 'template_query_port'];
        }

        $setupVars = $instance->getSetupVars();
        $requiredPorts = $instance->getTemplate()->getRequiredPorts();

        $hasDedicatedQueryPort = false;
        foreach ($requiredPorts as $definition) {
            $name = strtolower((string) ($definition['name'] ?? ''));
            if ($name === 'query' || str_contains($name, 'query')) {
                $hasDedicatedQueryPort = true;
                break;
            }
        }

        $queryPortBehavior = $this->resolveQueryPortBehavior($queryConfig);

        if ($queryType === 'steam_a2s') {
            if (is_numeric($setupVars['SV_QUERYPORT'] ?? null)) {
                return ['port' => (int) $setupVars['SV_QUERYPORT'], 'source' => 'setup_var_sv_queryport'];
            }

            $isSource2 = $this->isCs2Template($instance);
            if ($isSource2 || $queryPortBehavior === self::QUERY_PORT_BEHAVIOR_EXPLICIT) {
                $setupPortKeys = ['QUERY_PORT', 'STEAM_QUERY_PORT', 'GAME_PORT'];
            } elseif ($queryPortBehavior === self::QUERY_PORT_BEHAVIOR_PLUS_ONE) {
                $basePort = $this->resolveGamePortFromSetupVars($setupVars);
                if ($basePort !== null) {
                    return ['port' => $basePort + 1, 'source' => 'game_port_plus_one'];
                }
                $setupPortKeys = ['GAME_PORT', 'PORT', 'SERVER_PORT'];
            } else {
                $setupPortKeys = ['GAME_PORT', 'PORT', 'SERVER_PORT', 'QUERY_PORT', 'STEAM_QUERY_PORT'];
            }
        } else {
            $setupPortKeys = ['QUERY_PORT', 'GAME_PORT'];
        }

        foreach ($setupPortKeys as $key) {
            if (is_numeric($setupVars[$key] ?? null)) {
                return ['port' => (int) $setupVars[$key], 'source' => sprintf('setup_var_%s', strtolower($key))];
            }
        }

        foreach (['PORT', 'SERVER_PORT'] as $key) {
            if (is_numeric($setupVars[$key] ?? null)) {
                return ['port' => (int) $setupVars[$key], 'source' => sprintf('setup_var_%s', strtolower($key))];
            }
        }

        $queryPort = $this->resolvePort($portBlock, $requiredPorts, 'query');
        if ($queryPort !== null) {
            if ($queryType !== 'steam_a2s') {
                return ['port' => $queryPort, 'source' => 'required_query_port'];
            }

            if ($this->isCs2Template($instance) || $queryPortBehavior === self::QUERY_PORT_BEHAVIOR_EXPLICIT) {
                return ['port' => $queryPort, 'source' => 'required_query_port'];
            }
        }

        $gamePort = $this->resolvePort($portBlock, $requiredPorts, 'game');
        if ($gamePort !== null) {
            if ($queryType === 'steam_a2s' && $queryPortBehavior === self::QUERY_PORT_BEHAVIOR_PLUS_ONE && !$hasDedicatedQueryPort && !$this->isCs2Template($instance)) {
                return ['port' => $gamePort + 1, 'source' => 'required_game_port_plus_one'];
            }

            return ['port' => $gamePort, 'source' => 'required_game_port'];
        }

        if ($instance->getAssignedPort() !== null) {
            return ['port' => $instance->getAssignedPort(), 'source' => 'instance_assigned_port'];
        }

        return ['port' => null, 'source' => ''];
    }

    /**
     * @param array<string, mixed> $queryConfig
     */
    private function resolveQueryPortBehavior(array $queryConfig): string
    {
        $rawBehavior = strtolower(trim((string) ($queryConfig['query_port_behavior'] ?? $queryConfig['port_behavior'] ?? '')));

        return match ($rawBehavior) {
            'explicit', 'query_port', 'configured', 'fixed' => self::QUERY_PORT_BEHAVIOR_EXPLICIT,
            'plus_one', 'port_plus_one', '+1' => self::QUERY_PORT_BEHAVIOR_PLUS_ONE,
            default => self::QUERY_PORT_BEHAVIOR_SAME_AS_GAME_PORT,
        };
    }

    /**
     * @param array<string, mixed> $setupVars
     */
    private function resolveGamePortFromSetupVars(array $setupVars): ?int
    {
        foreach (['GAME_PORT', 'PORT', 'SERVER_PORT'] as $key) {
            if (is_numeric($setupVars[$key] ?? null)) {
                return (int) $setupVars[$key];
            }
        }

        return null;
    }


    private function isCs2Template(Instance $instance): bool
    {
        $gameKey = strtolower(trim($instance->getTemplate()->getGameKey()));

        return $gameKey === 'cs2' || str_starts_with($gameKey, 'cs2_') || str_contains($gameKey, '_cs2');
    }

    /**
     * @param array<int, array<string, mixed>> $requiredPorts
     */
    private function resolvePort(?PortBlock $portBlock, array $requiredPorts, string $target): ?int
    {
        if ($portBlock === null) {
            return null;
        }

        $ports = $portBlock->getPorts();

        foreach ($requiredPorts as $index => $definition) {
            if (!isset($ports[$index])) {
                continue;
            }
            $name = strtolower((string) ($definition['name'] ?? ''));
            if ($name === $target || str_contains($name, $target)) {
                return (int) $ports[$index];
            }
        }

        return null;
    }
}
