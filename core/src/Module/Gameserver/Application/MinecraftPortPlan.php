<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application;

use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\Template;
use App\Infrastructure\Config\JsonArray;
use App\Infrastructure\Config\JsonMap;
use App\Infrastructure\Config\ScalarValue;

/** @psalm-type PortDefinition = array{name: string, protocol: string, ...<array-key, mixed>} */
final class MinecraftPortPlan
{
    /** @return list<PortDefinition> */
    public static function definitions(Template $template, array $settings): array
    {
        if (!array_key_exists('EASYWI_MINECRAFT_MANAGED_PORTS', $settings)) {
            return self::normalizeDefinitions($template->getRequiredPorts());
        }
        $type = $template->getInstallResolver()['type'] ?? '';
        if (!in_array($type, ['minecraft_vanilla', 'papermc_paper', 'minecraft_spigot', 'minecraft_bedrock', 'minecraft_bungeecord'], true)) {
            return self::normalizeDefinitions($template->getRequiredPorts());
        }
        $ports = [['name' => 'game', 'label' => 'Game', 'protocol' => 'minecraft_bedrock' === $type ? 'udp' : 'tcp']];
        if ('minecraft_bedrock' === $type) {
            $ports[] = ['name' => 'gamev6', 'label' => 'Game IPv6', 'protocol' => 'udp'];

            return $ports;
        }
        if ('minecraft_bungeecord' !== $type && ($settings['EASYWI_MINECRAFT_RCON'] ?? '') === '1') {
            $ports[] = ['name' => 'rcon', 'label' => 'RCON', 'protocol' => 'tcp'];
        }
        if (($settings['EASYWI_MINECRAFT_QUERY'] ?? '') === '1') {
            $ports[] = ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'];
        }

        return $ports;
    }

    /**
     * Bedrock may use UDP RakNet or TCP signaling plus UDP NetherNet.
     * @param list<array{role: string, protocol: string, port: int}> $reservations
     * @return list<array{role: string, protocol: string, port: int}>
     */
    public static function withBedrockSignaling(Template $template, array $reservations): array
    {
        if (($template->getInstallResolver()['type'] ?? '') !== 'minecraft_bedrock') {
            return $reservations;
        }
        foreach ($reservations as $reservation) {
            if ('signaling' === $reservation['role']) {
                return $reservations;
            }
        }
        foreach ($reservations as $reservation) {
            if ('game' === $reservation['role']) {
                $reservations[] = ['role' => 'signaling', 'protocol' => 'tcp', 'port' => $reservation['port']];
                break;
            }
        }

        return $reservations;
    }

    /** @return list<PortDefinition> */
    public static function forInstance(Instance $instance): array
    {
        return self::definitions($instance->getTemplate(), $instance->getSetupVars());
    }

    /**
     * @template TKey of array-key
     * @template TValue
     * @param array<TKey, TValue> $existing
     * @return array<TKey|string, TValue|string>
     */
    public static function settings(Template $template, bool $rcon, bool $query, array $existing = []): array
    {
        $type = $template->getInstallResolver()['type'] ?? '';
        if (!in_array($type, ['minecraft_vanilla', 'papermc_paper', 'minecraft_spigot', 'minecraft_bedrock', 'minecraft_bungeecord'], true)) {
            return $existing;
        }
        $rcon = $rcon && !in_array($type, ['minecraft_bedrock', 'minecraft_bungeecord'], true);
        $existing['EASYWI_MINECRAFT_MANAGED_PORTS'] = '1';
        $existing['EASYWI_MINECRAFT_RCON'] = $rcon ? '1' : '0';
        $existing['EASYWI_MINECRAFT_QUERY'] = $query && 'minecraft_bedrock' !== $type ? '1' : '0';
        if ($rcon && '' === trim(ScalarValue::text($existing['RCON_PASSWORD'] ?? null))) {
            $existing['RCON_PASSWORD'] = bin2hex(random_bytes(24));
        }

        return $existing;
    }
    /**
     * @param array<array-key, mixed> $definitions
     * @return list<PortDefinition>
     */
    private static function normalizeDefinitions(array $definitions): array
    {
        $ports = [];
        foreach (array_keys($definitions) as $key) {
            $port = JsonArray::fromValue($definitions[$key]);
            $strings = JsonMap::strings($port);
            if ($port === null || !isset($strings['name']) || $strings['name'] === '') {
                throw new \InvalidArgumentException('Template port definition requires a name.');
            }
            $port['name'] = $strings['name'];
            $port['protocol'] = $strings['protocol'] ?? 'udp';
            $ports[] = $port;
        }

        return $ports;
    }
}
