<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application;

use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\Template;

final class MinecraftPortPlan
{
    public static function definitions(Template $template, array $settings): array
    {
        if (!array_key_exists('EASYWI_MINECRAFT_MANAGED_PORTS', $settings)) {
            return $template->getRequiredPorts();
        }
        $type = $template->getInstallResolver()['type'] ?? '';
        if (!in_array($type, ['minecraft_vanilla', 'papermc_paper', 'minecraft_spigot', 'minecraft_bedrock', 'minecraft_bungeecord'], true)) {
            return $template->getRequiredPorts();
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

    /** Bedrock may use UDP RakNet or TCP signaling plus UDP NetherNet. */
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

    public static function forInstance(Instance $instance): array
    {
        return self::definitions($instance->getTemplate(), $instance->getSetupVars());
    }

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
        if ($rcon && '' === trim((string) ($existing['RCON_PASSWORD'] ?? ''))) {
            $existing['RCON_PASSWORD'] = bin2hex(random_bytes(24));
        }

        return $existing;
    }
}
