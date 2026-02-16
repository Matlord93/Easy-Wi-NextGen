<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application\Query;

use App\Module\Core\Domain\Entity\Instance;
use App\Module\Ports\Domain\Entity\PortBlock;

final class InstanceQueryResolver
{
    private const DEFAULT_TIMEOUT_MS = 4000;

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

        $host = trim((string) $instance->getNode()->getLastHeartbeatIp());
        if ($host === '') {
            throw new InvalidInstanceQueryConfiguration('Query host is missing.');
        }

        $port = $this->resolveQueryPort($instance, $portBlock, $type, $queryConfig);
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
            ],
        );
    }

    /**
     * @param array<string, mixed> $requirements
     * @param mixed $queryConfigRaw
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
            'source', 'source_engine', 'source-engine', 'steam' => 'steam_a2s',
            'a2s', 'steam_a2s' => 'steam_a2s',
            'minecraft', 'minecraft_java', 'java' => 'minecraft_java',
            'minecraft_bedrock', 'bedrock', 'mcpe' => 'minecraft_bedrock',
            default => $declaredType,
        };
    }

    /**
     * @param array<string, mixed> $queryConfig
     */
    private function resolveQueryPort(Instance $instance, ?PortBlock $portBlock, string $queryType, array $queryConfig): ?int
    {
        if (is_numeric($queryConfig['port'] ?? null)) {
            return (int) $queryConfig['port'];
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

        if ($queryType === 'steam_a2s') {
            $setupPortKeys = $hasDedicatedQueryPort
                ? ['QUERY_PORT', 'STEAM_QUERY_PORT', 'GAME_PORT']
                : ['GAME_PORT', 'QUERY_PORT', 'STEAM_QUERY_PORT'];
        } else {
            $setupPortKeys = ['QUERY_PORT', 'GAME_PORT'];
        }

        foreach ($setupPortKeys as $key) {
            if (is_numeric($setupVars[$key] ?? null)) {
                return (int) $setupVars[$key];
            }
        }

        $queryPort = $this->resolvePort($portBlock, $requiredPorts, 'query');
        if ($queryPort !== null) {
            return $queryPort;
        }

        $gamePort = $this->resolvePort($portBlock, $requiredPorts, 'game');
        if ($gamePort !== null) {
            return $gamePort;
        }

        if ($instance->getAssignedPort() !== null) {
            return $instance->getAssignedPort();
        }

        return null;
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
