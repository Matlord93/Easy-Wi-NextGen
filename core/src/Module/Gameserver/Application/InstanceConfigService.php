<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application;

use App\Module\Core\Domain\Entity\Instance;
use App\Module\Gameserver\Domain\Entity\GameProfile;
use App\Module\Gameserver\Domain\Enum\EnforceMode;
use App\Module\Ports\Domain\Entity\PortAllocation;

final class InstanceConfigService
{
    /**
     * @param PortAllocation[] $allocations
     * @return array<string, mixed>
     */
    public function buildStartPayload(Instance $instance, GameProfile $profile, array $allocations): array
    {
        $ports = $this->mapPorts($allocations);
        $slots = $instance->getCurrentSlots();

        $payload = [
            'instance_id' => (string) ($instance->getId() ?? ''),
            'game_key' => $profile->getGameKey(),
            'ports' => $ports,
            'slots' => $slots,
            'config' => [],
            'command' => null,
            'args' => [],
            'env' => [],
            'work_dir' => null,
        ];

        $payload = $this->applyPortEnforcement($profile, $ports, $payload);
        $payload = $this->applySlotEnforcement($profile, $slots, $payload);

        return $payload;
    }

    /**
     * @param array<string, int> $ports
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function applyPortEnforcement(GameProfile $profile, array $ports, array $payload): array
    {
        if ($profile->getEnforceModePorts() === EnforceMode::EnforceByArgs) {
            return $this->buildArgsPayload($profile->getGameKey(), $ports, $payload);
        }

        return $this->buildConfigPayload($profile->getGameKey(), $ports, $payload);
    }

    /**
     * @param array<string, int> $ports
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function applySlotEnforcement(GameProfile $profile, int $slots, array $payload): array
    {
        if ($profile->getEnforceModeSlots() === EnforceMode::EnforceByArgs) {
            return $this->buildArgsSlotPayload($profile->getGameKey(), $slots, $payload);
        }

        return $this->buildConfigSlotPayload($profile->getGameKey(), $slots, $payload);
    }

    /**
     * @param array<string, int> $ports
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function buildArgsPayload(string $gameKey, array $ports, array $payload): array
    {
        return match ($gameKey) {
            'cs2', 'cs2_windows', 'csgo_legacy', 'csgo_legacy_windows', 'css', 'css_windows', 'tf2', 'tf2_windows', 'hl2dm', 'hl2dm_windows',
            'l4d', 'l4d_windows', 'l4d2', 'l4d2_windows', 'dods', 'dods_windows', 'garrys_mod' => $this->withArgs($payload, [
                '-port', (string) ($ports['GAME_PORT'] ?? 27015),
                '+ip', '0.0.0.0',
                '+hostport', (string) ($ports['GAME_PORT'] ?? 27015),
                '+clientport', (string) ($ports['GAME_PORT'] ?? 27015),
            ]),
            'rust', 'rust_windows' => $this->withArgs($payload, [
                '-port', (string) ($ports['GAME_PORT'] ?? 28015),
                '+rcon.port', (string) ($ports['RCON_PORT'] ?? 28016),
                '+query.port', (string) ($ports['QUERY_PORT'] ?? 28017),
            ]),
            default => $payload,
        };
    }

    /**
     * @param array<string, int> $ports
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function buildConfigPayload(string $gameKey, array $ports, array $payload): array
    {
        return match ($gameKey) {
            'seven_days_to_die' => $this->withConfig($payload, 'serverconfig.xml', [
                'SERVER_PORT' => (string) ($ports['GAME_PORT'] ?? 26900),
                'TELNET_PORT' => (string) ($ports['TELNET_PORT'] ?? 8081),
                'CONTROL_PANEL_PORT' => (string) ($ports['CONTROL_PANEL_PORT'] ?? 8082),
            ]),
            'minecraft_vanilla_all', 'minecraft_paper_all' => $this->withConfig($payload, 'server.properties', [
                'SERVER_PORT' => (string) ($ports['GAME_PORT'] ?? 25565),
            ]),
            'minecraft_bedrock' => $this->withConfig($payload, 'server.properties', [
                'SERVER_PORT' => (string) ($ports['GAME_PORT'] ?? 19132),
            ]),
            'fivem', 'fivem_windows' => $this->withConfig($payload, 'server.cfg', [
                'GAME_TCP' => (string) ($ports['GAME_TCP'] ?? 30120),
                'GAME_UDP' => (string) ($ports['GAME_UDP'] ?? 30120),
            ]),
            default => $payload,
        };
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function buildArgsSlotPayload(string $gameKey, int $slots, array $payload): array
    {
        return match ($gameKey) {
            'cs2', 'cs2_windows', 'csgo_legacy', 'csgo_legacy_windows', 'css', 'css_windows', 'tf2', 'tf2_windows', 'hl2dm', 'hl2dm_windows',
            'l4d', 'l4d_windows', 'l4d2', 'l4d2_windows', 'dods', 'dods_windows', 'garrys_mod' => $this->withArgs($payload, ['+maxplayers', (string) $slots]),
            'rust', 'rust_windows' => $this->withArgs($payload, ['+server.maxplayers', (string) $slots]),
            default => $payload,
        };
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function buildConfigSlotPayload(string $gameKey, int $slots, array $payload): array
    {
        return match ($gameKey) {
            'minecraft_vanilla_all', 'minecraft_paper_all' => $this->withConfig($payload, 'server.properties', [
                'MAX_PLAYERS' => (string) $slots,
            ]),
            'minecraft_bedrock' => $this->withConfig($payload, 'server.properties', [
                'MAX_PLAYERS' => (string) $slots,
            ]),
            'seven_days_to_die' => $this->withConfig($payload, 'serverconfig.xml', [
                'SERVER_MAX_PLAYERS' => (string) $slots,
            ]),
            'fivem', 'fivem_windows' => $this->withConfig($payload, 'server.cfg', [
                'SV_MAXCLIENTS' => (string) $slots,
            ]),
            default => $payload,
        };
    }

    /**
     * @param PortAllocation[] $allocations
     * @return array<string, int>
     */
    private function mapPorts(array $allocations): array
    {
        $ports = [];
        foreach ($allocations as $allocation) {
            $ports[$allocation->getRoleKey()] = $allocation->getPort();
        }

        return $ports;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $args
     * @return array<string, mixed>
     */
    private function withArgs(array $payload, array $args): array
    {
        $payload['command'] = $payload['command'] ?? 'start-server';
        $payload['args'] = array_values(array_merge($payload['args'] ?? [], $args));

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $values
     * @return array<string, mixed>
     */
    private function withConfig(array $payload, string $templatePath, array $values): array
    {
        $payload['config'] = $payload['config'] ?? [];
        foreach ($payload['config'] as $index => $entry) {
            if (($entry['template'] ?? null) === $templatePath) {
                $merged = array_merge($entry['values'] ?? [], $values);
                $payload['config'][$index] = [
                    'template' => $templatePath,
                    'values' => $merged,
                ];

                return $payload;
            }
        }

        $payload['config'][] = [
            'template' => $templatePath,
            'values' => $values,
        ];

        return $payload;
    }
}
