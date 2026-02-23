<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application;

use App\Module\Core\Domain\Entity\Instance;

final class ConfigTemplateRegistry
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function listTargetsForInstance(Instance $instance): array
    {
        $template = $instance->getTemplate();
        $gameKey = $template->getGameKey();
        $family = $this->engineFamily($gameKey);
        $supportedOs = array_values(array_filter(array_map(static fn (mixed $v): string => strtolower(trim((string) $v)), $template->getSupportedOs())));

        $targets = [];
        foreach ($template->getConfigFiles() as $index => $cfg) {
            if (!is_array($cfg)) {
                continue;
            }
            $relativePath = trim((string) ($cfg['path'] ?? ''));
            if ($relativePath === '') {
                continue;
            }

            $targetId = sprintf('%s.%d', $gameKey, $index + 1);
            $name = basename(str_replace('\\', '/', $relativePath));
            $schema = $this->schemaForPath($family, $relativePath);
            $targets[] = [
                'id' => $targetId,
                'game_key' => $gameKey,
                'engine_family' => $family,
                'display_name' => $name,
                'description' => (string) ($cfg['description'] ?? ''),
                'platform' => $supportedOs !== [] ? $supportedOs : ['linux'],
                'relative_path' => $relativePath,
                'apply_mode' => (string) ($schema['apply_mode'] ?? 'render_text'),
                'restart_hint' => 'recommended',
                'capabilities' => [
                    'supports_create' => true,
                    'supports_edit' => true,
                    'supports_validate' => true,
                ],
                'schema' => $schema,
                'unsupported_reason' => null,
            ];
        }

        if ($targets === []) {
            return [[
                'id' => $gameKey . '.unsupported',
                'game_key' => $gameKey,
                'engine_family' => $family,
                'display_name' => 'No known config target',
                'description' => 'Template catalog has no config_files entry for this game.',
                'platform' => $supportedOs !== [] ? $supportedOs : ['linux', 'windows'],
                'relative_path' => '',
                'apply_mode' => 'unsupported',
                'restart_hint' => 'none',
                'capabilities' => [
                    'supports_create' => false,
                    'supports_edit' => false,
                    'supports_validate' => false,
                ],
                'schema' => ['fields' => []],
                'unsupported_reason' => 'UNSUPPORTED_CONFIG_TARGET: no path evidence in template catalog',
            ]];
        }

        return $targets;
    }

    private function engineFamily(string $gameKey): string
    {
        $g = strtolower($gameKey);
        if (str_starts_with($g, 'minecraft_')) {
            return str_contains($g, 'bedrock') ? 'minecraft_bedrock' : 'minecraft_java';
        }
        $sourceKeys = ['cs2', 'csgo_legacy', 'tf2', 'css', 'hl2dm', 'l4d2', 'l4d', 'dods', 'garrys_mod'];
        foreach ($sourceKeys as $key) {
            if (str_starts_with($g, $key)) {
                return 'source';
            }
        }

        return 'generic';
    }

    /**
     * @return array<string, mixed>
     */
    private function schemaForPath(string $family, string $path): array
    {
        $normalized = strtolower(str_replace('\\', '/', $path));
        if ($family === 'source' && str_ends_with($normalized, 'server.cfg')) {
            return [
                'apply_mode' => 'merge_kv',
                'fields' => [
                    ['key' => 'hostname', 'label' => 'Hostname', 'type' => 'string', 'min' => 3, 'max' => 64],
                    ['key' => 'sv_password', 'label' => 'Server Password', 'type' => 'string', 'secret' => true],
                    ['key' => 'rcon_password', 'label' => 'RCON Password', 'type' => 'string', 'secret' => true],
                    ['key' => 'sv_lan', 'label' => 'LAN', 'type' => 'bool'],
                    ['key' => 'sv_region', 'label' => 'Region', 'type' => 'int'],
                    ['key' => 'sv_maxplayers', 'label' => 'Max Players', 'type' => 'int', 'min' => 1, 'max' => 128],
                    ['key' => 'sv_tags', 'label' => 'Tags', 'type' => 'string'],
                    ['key' => 'sv_cheats', 'label' => 'Cheats', 'type' => 'bool'],
                    ['key' => 'sv_downloadurl', 'label' => 'Download URL', 'type' => 'string'],
                    ['key' => 'sv_consistency', 'label' => 'Consistency', 'type' => 'bool'],
                ],
            ];
        }

        if ($family === 'minecraft_java' && str_ends_with($normalized, 'server.properties')) {
            return [
                'apply_mode' => 'properties',
                'fields' => [
                    ['key' => 'server-port', 'label' => 'Server Port', 'type' => 'int', 'min' => 1, 'max' => 65535, 'readonly' => true],
                    ['key' => 'motd', 'label' => 'MOTD', 'type' => 'string'],
                    ['key' => 'max-players', 'label' => 'Max Players', 'type' => 'int', 'min' => 1, 'max' => 500],
                    ['key' => 'online-mode', 'label' => 'Online Mode', 'type' => 'bool'],
                    ['key' => 'enable-rcon', 'label' => 'Enable RCON', 'type' => 'bool'],
                    ['key' => 'rcon.password', 'label' => 'RCON Password', 'type' => 'string', 'secret' => true],
                    ['key' => 'rcon.port', 'label' => 'RCON Port', 'type' => 'int', 'min' => 1, 'max' => 65535],
                    ['key' => 'level-name', 'label' => 'Level Name', 'type' => 'string'],
                    ['key' => 'difficulty', 'label' => 'Difficulty', 'type' => 'enum', 'allowed' => ['peaceful', 'easy', 'normal', 'hard']],
                    ['key' => 'gamemode', 'label' => 'Gamemode', 'type' => 'enum', 'allowed' => ['survival', 'creative', 'adventure', 'spectator']],
                    ['key' => 'pvp', 'label' => 'PVP', 'type' => 'bool'],
                    ['key' => 'view-distance', 'label' => 'View Distance', 'type' => 'int'],
                ],
            ];
        }

        return [
            'apply_mode' => 'render_text',
            'fields' => [],
            'raw_mode' => true,
        ];
    }
}
