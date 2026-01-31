<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application\Query;

use App\Module\Core\Domain\Enum\JobResultStatus;

final class QueryResultNormalizer
{
    /**
     * @param array<string, bool> $capabilities
     * @param array{players?: int|null, max_players?: int|null, map?: string|null, version?: string|null, motd?: string|null} $reported
     * @return array<string, mixed>
     */
    public static function build(
        ?bool $online,
        ?string $engine,
        ?int $latencyMs,
        ?string $error,
        array $reported,
        array $capabilities,
    ): array {
        return [
            'online' => $online,
            'engine' => $engine,
            'latency_ms' => $latencyMs,
            'error' => $error,
            'reported' => [
                'players' => $reported['players'] ?? null,
                'max_players' => $reported['max_players'] ?? null,
                'map' => $reported['map'] ?? null,
                'version' => $reported['version'] ?? null,
                'motd' => $reported['motd'] ?? null,
            ],
            'capabilities' => [
                'players' => (bool) ($capabilities['players'] ?? false),
                'slots' => (bool) ($capabilities['slots'] ?? false),
                'map' => (bool) ($capabilities['map'] ?? false),
                'version' => (bool) ($capabilities['version'] ?? false),
                'motd' => (bool) ($capabilities['motd'] ?? false),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $output
     * @return array<string, mixed>
     */
    public static function fromAgentOutput(array $output, ?string $queryType, JobResultStatus $resultStatus): array
    {
        $engine = self::normalizeEngine($queryType);
        $capabilities = self::capabilitiesForEngine($engine);

        $latencyMs = isset($output['latency_ms']) && is_numeric($output['latency_ms'])
            ? (int) $output['latency_ms']
            : null;

        $status = is_string($output['status'] ?? null) ? strtolower((string) $output['status']) : null;
        $online = self::normalizeOnlineStatus($status, $resultStatus);

        $error = is_string($output['message'] ?? null) ? (string) $output['message'] : null;

        $reported = [
            'players' => $resultStatus === JobResultStatus::Succeeded && is_numeric($output['players'] ?? null)
                ? (int) $output['players']
                : null,
            'max_players' => $resultStatus === JobResultStatus::Succeeded && is_numeric($output['max_players'] ?? null)
                ? (int) $output['max_players']
                : null,
            'map' => $resultStatus === JobResultStatus::Succeeded && is_string($output['map'] ?? null)
                ? (string) $output['map']
                : null,
            'version' => $resultStatus === JobResultStatus::Succeeded && is_string($output['version'] ?? null)
                ? (string) $output['version']
                : null,
            'motd' => $resultStatus === JobResultStatus::Succeeded && is_string($output['motd'] ?? null)
                ? (string) $output['motd']
                : null,
        ];

        return self::build($online, $engine, $latencyMs, $error, $reported, $capabilities);
    }

    /**
     * @param array<string, mixed> $cache
     * @return array<string, mixed>
     */
    public static function fromLegacyCache(array $cache, string $queryType): array
    {
        $engine = self::normalizeEngine($queryType);
        $capabilities = self::capabilitiesForEngine($engine);
        $status = is_string($cache['status'] ?? null) ? strtolower((string) $cache['status']) : null;
        $online = self::normalizeOnlineStatus($status, JobResultStatus::Succeeded);
        $error = is_string($cache['message'] ?? null) ? (string) $cache['message'] : null;

        $reported = [
            'players' => isset($cache['players']) && is_numeric($cache['players']) ? (int) $cache['players'] : null,
            'max_players' => isset($cache['max_players']) && is_numeric($cache['max_players']) ? (int) $cache['max_players'] : null,
            'map' => is_string($cache['map'] ?? null) ? (string) $cache['map'] : null,
            'version' => is_string($cache['version'] ?? null) ? (string) $cache['version'] : null,
            'motd' => is_string($cache['motd'] ?? null) ? (string) $cache['motd'] : null,
        ];

        $latencyMs = isset($cache['latency_ms']) && is_numeric($cache['latency_ms'])
            ? (int) $cache['latency_ms']
            : null;

        return self::build($online, $engine, $latencyMs, $error, $reported, $capabilities);
    }

    private static function normalizeEngine(?string $queryType): ?string
    {
        $type = strtolower(trim((string) $queryType));

        return match ($type) {
            'steam_a2s', 'a2s' => 'source',
            'minecraft_java', 'minecraft' => 'minecraft_java',
            'minecraft_bedrock', 'bedrock' => 'minecraft_bedrock',
            'http' => 'http',
            'rcon' => 'rcon',
            default => $type !== '' ? $type : null,
        };
    }

    /**
     * @return array<string, bool>
     */
    private static function capabilitiesForEngine(?string $engine): array
    {
        return match ($engine) {
            'source' => [
                'players' => true,
                'slots' => true,
                'map' => true,
                'version' => false,
                'motd' => false,
            ],
            'minecraft_java' => [
                'players' => true,
                'slots' => true,
                'map' => false,
                'version' => true,
                'motd' => true,
            ],
            'minecraft_bedrock' => [
                'players' => true,
                'slots' => true,
                'map' => false,
                'version' => true,
                'motd' => true,
            ],
            default => [
                'players' => false,
                'slots' => false,
                'map' => false,
                'version' => false,
                'motd' => false,
            ],
        };
    }

    private static function normalizeOnlineStatus(?string $status, JobResultStatus $resultStatus): ?bool
    {
        if ($resultStatus !== JobResultStatus::Succeeded) {
            return null;
        }

        return match ($status) {
            'online', 'running', 'up' => true,
            'offline', 'stopped', 'down', 'error' => false,
            default => null,
        };
    }
}
