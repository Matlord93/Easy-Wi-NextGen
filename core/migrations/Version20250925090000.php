<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250925090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed classic Easy-Wi templates for legacy and popular games.';
    }

    public function up(Schema $schema): void
    {
        $this->insertTemplate(
            'ark_windows',
            'ARK: Survival Evolved (Windows)',
            'SteamCMD install with Windows server binary.',
            376030,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                ['name' => 'rcon', 'label' => 'RCON', 'protocol' => 'tcp'],
            ],
            '{{INSTANCE_DIR}}/ShooterGameServer.exe TheIsland?SessionName={{SERVER_NAME}}?Port={{PORT_GAME}}?QueryPort={{PORT_QUERY}}?RCONPort={{PORT_RCON}}?MaxPlayers=70?listen -log',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi ARK'],
            ],
            [
                [
                    'path' => 'ShooterGame/Saved/Config/WindowsServer/GameUserSettings.ini',
                    'description' => 'Server session settings',
                ],
                [
                    'path' => 'ShooterGame/Saved/Config/WindowsServer/Game.ini',
                    'description' => 'Gameplay rules',
                ],
            ],
            [],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login anonymous +app_update 376030 validate +quit',
            'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login anonymous +app_update 376030 +quit',
            []
        );

        $this->insertTemplate(
            'rust_windows',
            'Rust Dedicated Server (Windows)',
            'SteamCMD install with Windows server binary.',
            258550,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                ['name' => 'rcon', 'label' => 'RCON', 'protocol' => 'tcp'],
            ],
            '{{INSTANCE_DIR}}/RustDedicated.exe -batchmode +server.port {{PORT_GAME}} +server.queryport {{PORT_QUERY}} +rcon.port {{PORT_RCON}} +server.hostname "{{SERVER_NAME}}" +rcon.password "{{RCON_PASSWORD}}" +server.maxplayers 50',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi Rust'],
                ['key' => 'RCON_PASSWORD', 'value' => 'change-me'],
            ],
            [
                [
                    'path' => 'server/cfg/server.cfg',
                    'description' => 'Server configuration overrides',
                ],
            ],
            [
                'oxide/plugins',
            ],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login anonymous +app_update 258550 validate +quit',
            'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login anonymous +app_update 258550 +quit',
            ['+server.level', '+server.seed']
        );

        $this->insertTemplate(
            'enshrouded',
            'Enshrouded Dedicated Server',
            'SteamCMD install with Linux server binary.',
            2278520,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
            ],
            './enshrouded_server -log',
            [],
            [
                [
                    'path' => 'enshrouded_server.json',
                    'description' => 'Server configuration',
                ],
            ],
            [],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd +force_install_dir {{INSTANCE_DIR}} +login anonymous +app_update 2278520 validate +quit',
            'steamcmd +force_install_dir {{INSTANCE_DIR}} +login anonymous +app_update 2278520 +quit',
            []
        );

        $this->insertTemplate(
            'squad',
            'Squad Dedicated Server',
            'SteamCMD install with default server config.',
            403240,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                ['name' => 'rcon', 'label' => 'RCON', 'protocol' => 'tcp'],
            ],
            './SquadGameServer.sh Port={{PORT_GAME}} QueryPort={{PORT_QUERY}} RCONPort={{PORT_RCON}} -log -MaxPlayers={{MAX_PLAYERS}}',
            [
                ['key' => 'MAX_PLAYERS', 'value' => '80'],
            ],
            [
                [
                    'path' => 'SquadGame/ServerConfig/Server.cfg',
                    'description' => 'Server configuration',
                ],
            ],
            [],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd +force_install_dir {{INSTANCE_DIR}} +login anonymous +app_update 403240 validate +quit',
            'steamcmd +force_install_dir {{INSTANCE_DIR}} +login anonymous +app_update 403240 +quit',
            []
        );

        $this->insertTemplate(
            'squad_windows',
            'Squad Dedicated Server (Windows)',
            'SteamCMD install with Windows server binary.',
            403240,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                ['name' => 'rcon', 'label' => 'RCON', 'protocol' => 'tcp'],
            ],
            '{{INSTANCE_DIR}}/SquadGameServer.exe Port={{PORT_GAME}} QueryPort={{PORT_QUERY}} RCONPort={{PORT_RCON}} -log -MaxPlayers={{MAX_PLAYERS}}',
            [
                ['key' => 'MAX_PLAYERS', 'value' => '80'],
            ],
            [
                [
                    'path' => 'SquadGame/ServerConfig/Server.cfg',
                    'description' => 'Server configuration',
                ],
            ],
            [],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login anonymous +app_update 403240 validate +quit',
            'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login anonymous +app_update 403240 +quit',
            []
        );

        $this->insertTemplate(
            'fivem',
            'FiveM (FXServer)',
            'Download latest FXServer artifacts and run server.cfg.',
            null,
            null,
            [
                ['name' => 'game_udp', 'label' => 'Game (UDP)', 'protocol' => 'udp'],
                ['name' => 'game_tcp', 'label' => 'Game (TCP)', 'protocol' => 'tcp'],
            ],
            '{{INSTANCE_DIR}}/run.sh +exec server.cfg',
            [],
            [
                [
                    'path' => 'server.cfg',
                    'description' => 'Base FXServer configuration',
                    'contents' => "endpoint_add_tcp \"0.0.0.0:{{PORT_GAME_TCP}}\"\nendpoint_add_udp \"0.0.0.0:{{PORT_GAME_UDP}}\"\nsv_hostname \"Easy-Wi FiveM\"\n",
                ],
            ],
            [],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'cd {{INSTANCE_DIR}} && mkdir -p ./ && curl -sSL https://runtime.fivem.net/artifacts/fivem/build_proot_linux/master/ | grep -oP \'href="[^"]+fx.tar.xz"\' | tail -1 | cut -d\'"\' -f2 | xargs -I {} curl -L -o fx.tar.xz https://runtime.fivem.net/artifacts/fivem/build_proot_linux/master/{} && tar -xf fx.tar.xz && chmod +x run.sh',
            'cd {{INSTANCE_DIR}} && mkdir -p ./ && curl -sSL https://runtime.fivem.net/artifacts/fivem/build_proot_linux/master/ | grep -oP \'href="[^"]+fx.tar.xz"\' | tail -1 | cut -d\'"\' -f2 | xargs -I {} curl -L -o fx.tar.xz https://runtime.fivem.net/artifacts/fivem/build_proot_linux/master/{} && tar -xf fx.tar.xz && chmod +x run.sh',
            []
        );

        $this->insertTemplate(
            'fivem_windows',
            'FiveM (FXServer) (Windows)',
            'Download latest FXServer Windows artifacts and run server.cfg.',
            null,
            null,
            [
                ['name' => 'game_udp', 'label' => 'Game (UDP)', 'protocol' => 'udp'],
                ['name' => 'game_tcp', 'label' => 'Game (TCP)', 'protocol' => 'tcp'],
            ],
            '{{INSTANCE_DIR}}/FXServer.exe +exec server.cfg',
            [],
            [
                [
                    'path' => 'server.cfg',
                    'description' => 'Base FXServer configuration',
                    'contents' => "endpoint_add_tcp \"0.0.0.0:{{PORT_GAME_TCP}}\"\nendpoint_add_udp \"0.0.0.0:{{PORT_GAME_UDP}}\"\nsv_hostname \"Easy-Wi FiveM\"\n",
                ],
            ],
            [],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'powershell -Command "$ProgressPreference = \'SilentlyContinue\'; $page = Invoke-WebRequest -UseBasicParsing https://runtime.fivem.net/artifacts/fivem/build_server_windows/master/; $match = ($page.Links | Where-Object href -match \'server.zip\' | Select-Object -Last 1).href; Invoke-WebRequest -UseBasicParsing -OutFile server.zip (\"https://runtime.fivem.net/artifacts/fivem/build_server_windows/master/$match\"); Expand-Archive -Force server.zip ."',
            'powershell -Command "$ProgressPreference = \'SilentlyContinue\'; $page = Invoke-WebRequest -UseBasicParsing https://runtime.fivem.net/artifacts/fivem/build_server_windows/master/; $match = ($page.Links | Where-Object href -match \'server.zip\' | Select-Object -Last 1).href; Invoke-WebRequest -UseBasicParsing -OutFile server.zip (\"https://runtime.fivem.net/artifacts/fivem/build_server_windows/master/$match\"); Expand-Archive -Force server.zip ."',
            []
        );

        $this->insertTemplate(
            'teamspeak3',
            'TeamSpeak 3 Server',
            'Download and run TeamSpeak 3 server binaries.',
            null,
            null,
            [
                ['name' => 'voice', 'label' => 'Voice', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'tcp'],
                ['name' => 'filetransfer', 'label' => 'File Transfer', 'protocol' => 'tcp'],
            ],
            './ts3server_minimal_runscript.sh default_voice_port={{PORT_VOICE}} query_port={{PORT_QUERY}} filetransfer_port={{PORT_FILETRANSFER}} serveradmin_password={{SERVER_ADMIN_PASSWORD}}',
            [
                ['key' => 'SERVER_ADMIN_PASSWORD', 'value' => 'change-me'],
            ],
            [],
            [],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'cd {{INSTANCE_DIR}} && curl -L -o ts3.tar.bz2 https://files.teamspeak-services.com/releases/server/3.13.7/teamspeak3-server_linux_amd64-3.13.7.tar.bz2 && tar -xjf ts3.tar.bz2 --strip-components=1',
            'cd {{INSTANCE_DIR}} && curl -L -o ts3.tar.bz2 https://files.teamspeak-services.com/releases/server/3.13.7/teamspeak3-server_linux_amd64-3.13.7.tar.bz2 && tar -xjf ts3.tar.bz2 --strip-components=1',
            []
        );

        $this->insertTemplate(
            'teamspeak3_windows',
            'TeamSpeak 3 Server (Windows)',
            'Download and run TeamSpeak 3 Windows binaries.',
            null,
            null,
            [
                ['name' => 'voice', 'label' => 'Voice', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'tcp'],
                ['name' => 'filetransfer', 'label' => 'File Transfer', 'protocol' => 'tcp'],
            ],
            '{{INSTANCE_DIR}}/ts3server.exe default_voice_port={{PORT_VOICE}} query_port={{PORT_QUERY}} filetransfer_port={{PORT_FILETRANSFER}} serveradmin_password={{SERVER_ADMIN_PASSWORD}}',
            [
                ['key' => 'SERVER_ADMIN_PASSWORD', 'value' => 'change-me'],
            ],
            [],
            [],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'powershell -Command "$ProgressPreference = \'SilentlyContinue\'; Invoke-WebRequest -UseBasicParsing -OutFile ts3.zip https://files.teamspeak-services.com/releases/server/3.13.7/teamspeak3-server_win64-3.13.7.zip; Expand-Archive -Force ts3.zip ."',
            'powershell -Command "$ProgressPreference = \'SilentlyContinue\'; Invoke-WebRequest -UseBasicParsing -OutFile ts3.zip https://files.teamspeak-services.com/releases/server/3.13.7/teamspeak3-server_win64-3.13.7.zip; Expand-Archive -Force ts3.zip ."',
            []
        );
    }

    public function down(Schema $schema): void
    {
        $this->deleteTemplate('ark_windows');
        $this->deleteTemplate('rust_windows');
        $this->deleteTemplate('enshrouded');
        $this->deleteTemplate('squad');
        $this->deleteTemplate('squad_windows');
        $this->deleteTemplate('fivem');
        $this->deleteTemplate('fivem_windows');
        $this->deleteTemplate('teamspeak3');
        $this->deleteTemplate('teamspeak3_windows');
    }

    private function insertTemplate(
        string $gameKey,
        string $displayName,
        ?string $description,
        ?int $steamAppId,
        ?string $sniperProfile,
        array $requiredPorts,
        string $startParams,
        array $envVars,
        array $configFiles,
        array $pluginPaths,
        array $fastdlSettings,
        string $installCommand,
        string $updateCommand,
        array $allowedSwitchFlags,
    ): void {
        $columns = [
            'game_key',
            'display_name',
            'description',
            'steam_app_id',
            'sniper_profile',
            'required_ports',
            'start_params',
            'env_vars',
            'config_files',
            'plugin_paths',
            'fastdl_settings',
            'install_command',
            'update_command',
            'allowed_switch_flags',
        ];

        $values = [
            $this->quote($gameKey),
            $this->quote($displayName),
            $this->quote($description),
            $steamAppId === null ? 'NULL' : (string) $steamAppId,
            $this->quote($sniperProfile),
            $this->quoteJson($requiredPorts),
            $this->quote($startParams),
            $this->quoteJson($envVars),
            $this->quoteJson($configFiles),
            $this->quoteJson($pluginPaths),
            $this->quoteJson($fastdlSettings),
            $this->quote($installCommand),
            $this->quote($updateCommand),
            $this->quoteJson($allowedSwitchFlags),
        ];

        if ($this->hasColumn('game_templates', 'supported_os')) {
            $columns[] = 'supported_os';
            $columns[] = 'port_profile';
            $columns[] = 'requirements';
            $values[] = $this->quoteJson($this->resolveSupportedOs($gameKey));
            $values[] = $this->quoteJson($this->buildPortProfile($requiredPorts));
            $values[] = $this->quoteJson($this->buildRequirements($gameKey, $steamAppId, $envVars));
        }

        $columns[] = 'created_at';
        $columns[] = 'updated_at';
        $values[] = $this->currentTimestampExpression();
        $values[] = $this->currentTimestampExpression();

        $sql = sprintf(
            'INSERT INTO game_templates (%s) SELECT %s WHERE NOT EXISTS (SELECT 1 FROM game_templates WHERE game_key = %s)',
            implode(', ', $columns),
            implode(', ', $values),
            $this->quote($gameKey),
        );

        $this->addSql($sql);
    }

    private function deleteTemplate(string $gameKey): void
    {
        $this->addSql(sprintf(
            'DELETE FROM game_templates WHERE game_key = %s',
            $this->quote($gameKey),
        ));
    }

    private function quote(?string $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        return $this->connection->quote($value);
    }

    private function quoteJson(array $value): string
    {
        return $this->connection->quote($this->jsonEncode($value));
    }

    private function jsonEncode(array $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '[]' : $encoded;
    }

    /**
     * @param array<int, array<string, mixed>> $requiredPorts
     * @return array<int, array<string, mixed>>
     */
    private function buildPortProfile(array $requiredPorts): array
    {
        $roleMap = [
            'game' => 'game',
            'query' => 'query',
            'rcon' => 'rcon',
            'tv' => 'tv',
            'voice' => 'voice',
            'filetransfer' => 'filetransfer',
        ];

        $profile = [];
        foreach ($requiredPorts as $port) {
            if (!is_array($port)) {
                continue;
            }
            $name = strtolower((string) ($port['name'] ?? 'game'));
            $role = $roleMap[$name] ?? $name;
            $protocol = (string) ($port['protocol'] ?? 'udp');
            $count = (int) ($port['count'] ?? 1);
            if ($count <= 0) {
                $count = 1;
            }

            $profile[] = [
                'role' => $role,
                'protocol' => $protocol,
                'count' => $count,
                'required' => isset($port['required']) ? (bool) $port['required'] : true,
                'contiguous' => isset($port['contiguous']) ? (bool) $port['contiguous'] : false,
            ];
        }

        return $profile;
    }

    /**
     * @param array<int, array<string, mixed>> $envVars
     * @return array<string, mixed>
     */
    private function buildRequirements(string $gameKey, ?int $steamAppId, array $envVars): array
    {
        $envVarKeys = $this->extractEnvVarKeys($envVars);
        $requiredSecrets = $this->isCsTemplate($gameKey) ? ['STEAM_GSLT'] : [];

        return [
            'required_vars' => $envVarKeys,
            'required_secrets' => $requiredSecrets,
            'steam_install_mode' => $this->resolveSteamInstallMode($gameKey, $steamAppId),
            'customer_allowed_vars' => $envVarKeys,
            'customer_allowed_secrets' => $requiredSecrets,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $envVars
     * @return array<int, string>
     */
    private function extractEnvVarKeys(array $envVars): array
    {
        $keys = [];
        foreach ($envVars as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $key = trim((string) ($entry['key'] ?? ''));
            if ($key !== '') {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @return array<int, string>
     */
    private function resolveSupportedOs(string $gameKey): array
    {
        return str_ends_with($gameKey, '_windows') ? ['windows'] : ['linux'];
    }

    private function resolveSteamInstallMode(string $gameKey, ?int $steamAppId): string
    {
        if ($this->isMinecraftNoSteam($gameKey)) {
            return 'none';
        }

        return $steamAppId !== null ? 'anonymous' : 'none';
    }

    private function isMinecraftNoSteam(string $gameKey): bool
    {
        return in_array($gameKey, [
            'minecraft_paper',
            'minecraft_vanilla',
            'minecraft_paper_windows',
            'minecraft_vanilla_windows',
        ], true);
    }

    private function isCsTemplate(string $gameKey): bool
    {
        return in_array($gameKey, [
            'cs2',
            'csgo_legacy',
            'cs2_windows',
            'csgo_legacy_windows',
        ], true);
    }

    private function currentTimestampExpression(): string
    {
        return $this->isSqlite() ? 'CURRENT_TIMESTAMP' : 'CURRENT_TIMESTAMP()';
    }

    private function isSqlite(): bool
    {
        $platform = $this->connection->getDatabasePlatform();

        if (method_exists($platform, 'getName')) {
            return in_array($platform->getName(), ['sqlite', 'sqlite3'], true);
        }

        return $platform instanceof \Doctrine\DBAL\Platforms\SqlitePlatform
            || $platform instanceof \Doctrine\DBAL\Platforms\SQLitePlatform;
    }

    private function hasColumn(string $table, string $column): bool
    {
        $columns = $this->connection->createSchemaManager()->listTableColumns($table);

        return array_key_exists($column, $columns);
    }
}
