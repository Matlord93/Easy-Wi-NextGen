<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250221100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Finalize game template schema and seed initial templates.';
    }

    public function up(Schema $schema): void
    {
        if ($this->isSqlite()) {
            $this->addSql('ALTER TABLE game_templates RENAME COLUMN name TO display_name');
            $this->addSql('ALTER TABLE game_templates ADD COLUMN game_key VARCHAR(80) DEFAULT NULL');
            $this->addSql('ALTER TABLE game_templates ADD COLUMN steam_app_id INT DEFAULT NULL');
            $this->addSql('ALTER TABLE game_templates ADD COLUMN sniper_profile VARCHAR(120) DEFAULT NULL');
            $this->addSql('ALTER TABLE game_templates ADD COLUMN env_vars JSON DEFAULT NULL');
            $this->addSql('ALTER TABLE game_templates ADD COLUMN config_files JSON DEFAULT NULL');
            $this->addSql('ALTER TABLE game_templates ADD COLUMN plugin_paths JSON DEFAULT NULL');
            $this->addSql('ALTER TABLE game_templates ADD COLUMN fastdl_settings JSON DEFAULT NULL');
        } else {
            $this->addSql('ALTER TABLE game_templates CHANGE name display_name VARCHAR(120) NOT NULL');
            $this->addSql('ALTER TABLE game_templates ADD game_key VARCHAR(80) DEFAULT NULL, ADD steam_app_id INT DEFAULT NULL, ADD sniper_profile VARCHAR(120) DEFAULT NULL, ADD env_vars JSON DEFAULT NULL, ADD config_files JSON DEFAULT NULL, ADD plugin_paths JSON DEFAULT NULL, ADD fastdl_settings JSON DEFAULT NULL');
        }

        $this->addSql(sprintf(
            "UPDATE game_templates SET game_key = %s WHERE game_key IS NULL",
            $this->isSqlite() ? "'legacy-' || id" : "CONCAT('legacy-', id)"
        ));
        $this->addSql("UPDATE game_templates SET env_vars = '[]' WHERE env_vars IS NULL");
        $this->addSql("UPDATE game_templates SET config_files = '[]' WHERE config_files IS NULL");
        $this->addSql("UPDATE game_templates SET plugin_paths = '[]' WHERE plugin_paths IS NULL");
        $this->addSql("UPDATE game_templates SET fastdl_settings = '{}' WHERE fastdl_settings IS NULL");

        if (!$this->isSqlite()) {
            $this->addSql('ALTER TABLE game_templates MODIFY game_key VARCHAR(80) NOT NULL');
            $this->addSql('ALTER TABLE game_templates MODIFY env_vars JSON NOT NULL');
            $this->addSql('ALTER TABLE game_templates MODIFY config_files JSON NOT NULL');
            $this->addSql('ALTER TABLE game_templates MODIFY plugin_paths JSON NOT NULL');
            $this->addSql('ALTER TABLE game_templates MODIFY fastdl_settings JSON NOT NULL');
        }

        $this->insertTemplate(
            'cs2',
            'Counter-Strike 2 Dedicated Server',
            'SteamCMD install with Metamod + CounterStrikeSharp ready.',
            730,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                ['name' => 'rcon', 'label' => 'RCON', 'protocol' => 'tcp'],
                ['name' => 'tv', 'label' => 'SourceTV', 'protocol' => 'udp'],
            ],
            'srcds_run -game cs2 -console -usercon -tickrate 128 +map de_dust2 +sv_setsteamaccount {{STEAM_GSLT}} +hostname "{{SERVER_NAME}}" +rcon_password "{{RCON_PASSWORD}}"',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi CS2'],
                ['key' => 'SERVER_PASSWORD', 'value' => ''],
                ['key' => 'RCON_PASSWORD', 'value' => 'change-me'],
                ['key' => 'STEAM_GSLT', 'value' => ''],
            ],
            [
                [
                    'path' => 'game/csgo/cfg/server.cfg',
                    'description' => 'Base server configuration',
                    'contents' => "hostname \"{{SERVER_NAME}}\"\nrcon_password \"{{RCON_PASSWORD}}\"\nsv_password \"{{SERVER_PASSWORD}}\"\nsv_lan 0\n",
                ],
            ],
            [
                'game/csgo/addons/metamod',
                'game/csgo/addons/counterstrikesharp',
            ],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd +login anonymous +force_install_dir /srv/gs +app_update 730 validate +quit',
            'steamcmd +login anonymous +force_install_dir /srv/gs +app_update 730 +quit',
            ['+map', '+maxplayers', '+game_type', '+game_mode']
        );

        $this->insertTemplate(
            'csgo_legacy',
            'Counter-Strike: Global Offensive (Legacy)',
            'Legacy CS:GO server for legacy mod stacks.',
            740,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                ['name' => 'rcon', 'label' => 'RCON', 'protocol' => 'tcp'],
                ['name' => 'tv', 'label' => 'SourceTV', 'protocol' => 'udp'],
            ],
            'srcds_run -game csgo -console -usercon -tickrate 128 +map de_dust2 +sv_setsteamaccount {{STEAM_GSLT}} +hostname "{{SERVER_NAME}}" +rcon_password "{{RCON_PASSWORD}}"',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi CSGO'],
                ['key' => 'SERVER_PASSWORD', 'value' => ''],
                ['key' => 'RCON_PASSWORD', 'value' => 'change-me'],
                ['key' => 'STEAM_GSLT', 'value' => ''],
            ],
            [
                [
                    'path' => 'csgo/cfg/server.cfg',
                    'description' => 'Base server configuration',
                    'contents' => "hostname \"{{SERVER_NAME}}\"\nrcon_password \"{{RCON_PASSWORD}}\"\nsv_password \"{{SERVER_PASSWORD}}\"\nsv_lan 0\n",
                ],
            ],
            [
                'csgo/addons/metamod',
                'csgo/addons/sourcemod',
            ],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd +login anonymous +force_install_dir /srv/gs +app_update 740 validate +quit',
            'steamcmd +login anonymous +force_install_dir /srv/gs +app_update 740 +quit',
            ['+map', '+maxplayers']
        );

        $this->insertTemplate(
            'rust',
            'Rust Dedicated Server',
            'SteamCMD install with optional Oxide/uMod hooks.',
            258550,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                ['name' => 'rcon', 'label' => 'RCON', 'protocol' => 'tcp'],
            ],
            './RustDedicated -batchmode +server.port {{PORT_GAME}} +server.queryport {{PORT_QUERY}} +rcon.port {{PORT_RCON}} +server.hostname "{{SERVER_NAME}}" +rcon.password "{{RCON_PASSWORD}}" +server.maxplayers 50',
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
            'steamcmd +login anonymous +force_install_dir /srv/gs +app_update 258550 validate +quit',
            'steamcmd +login anonymous +force_install_dir /srv/gs +app_update 258550 +quit',
            ['+server.level', '+server.seed']
        );

        $this->insertTemplate(
            'ark',
            'ARK: Survival Evolved',
            'SteamCMD install with default Linux server config paths.',
            376030,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                ['name' => 'rcon', 'label' => 'RCON', 'protocol' => 'tcp'],
            ],
            'ShooterGameServer TheIsland?SessionName={{SERVER_NAME}}?Port={{PORT_GAME}}?QueryPort={{PORT_QUERY}}?RCONPort={{PORT_RCON}}?MaxPlayers=70?listen -log',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi ARK'],
                ['key' => 'ADMIN_PASSWORD', 'value' => 'change-me'],
            ],
            [
                [
                    'path' => 'ShooterGame/Saved/Config/LinuxServer/GameUserSettings.ini',
                    'description' => 'Server session settings',
                ],
                [
                    'path' => 'ShooterGame/Saved/Config/LinuxServer/Game.ini',
                    'description' => 'Gameplay rules',
                ],
            ],
            [],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd +login anonymous +force_install_dir /srv/gs +app_update 376030 validate +quit',
            'steamcmd +login anonymous +force_install_dir /srv/gs +app_update 376030 +quit',
            []
        );

        $this->insertTemplate(
            'valheim',
            'Valheim Dedicated Server',
            'SteamCMD install with fixed start params.',
            896660,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
            ],
            './valheim_server.x86_64 -name "{{SERVER_NAME}}" -port {{PORT_GAME}} -world "{{WORLD_NAME}}" -password "{{SERVER_PASSWORD}}" -public 1',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi Valheim'],
                ['key' => 'WORLD_NAME', 'value' => 'Dedicated'],
                ['key' => 'SERVER_PASSWORD', 'value' => 'change-me'],
            ],
            [],
            [],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd +login anonymous +force_install_dir /srv/gs +app_update 896660 validate +quit',
            'steamcmd +login anonymous +force_install_dir /srv/gs +app_update 896660 +quit',
            []
        );

        $this->insertTemplate(
            'minecraft_java',
            'Minecraft Java (Paper)',
            'PaperMC install with EULA acceptance and fixed JVM memory flags.',
            null,
            null,
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'tcp'],
            ],
            'java -Xms{{JAVA_XMS}} -Xmx{{JAVA_XMX}} -jar server.jar nogui',
            [
                ['key' => 'JAVA_XMS', 'value' => '1G'],
                ['key' => 'JAVA_XMX', 'value' => '2G'],
            ],
            [
                [
                    'path' => 'eula.txt',
                    'description' => 'Minecraft EULA acceptance',
                    'contents' => "eula=true\n",
                ],
                [
                    'path' => 'server.properties',
                    'description' => 'Base server settings',
                    'contents' => "motd=Easy-Wi Minecraft\nview-distance=10\n",
                ],
            ],
            [],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'curl -L -o server.jar https://api.papermc.io/v2/projects/paper/versions/1.20.4/builds/1/downloads/paper-1.20.4-1.jar',
            'curl -L -o server.jar https://api.papermc.io/v2/projects/paper/versions/1.20.4/builds/1/downloads/paper-1.20.4-1.jar',
            []
        );

        $this->insertTemplate(
            'satisfactory',
            'Satisfactory Dedicated Server',
            'SteamCMD install with default server settings paths.',
            1690800,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
            ],
            './FactoryServer.sh -log -unattended',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi Satisfactory'],
            ],
            [
                [
                    'path' => 'FactoryGame/Saved/Config/LinuxServer/ServerSettings.ini',
                    'description' => 'Server settings',
                ],
            ],
            [],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd +login anonymous +force_install_dir /srv/gs +app_update 1690800 validate +quit',
            'steamcmd +login anonymous +force_install_dir /srv/gs +app_update 1690800 +quit',
            []
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM game_templates WHERE game_key IN ('cs2','csgo_legacy','rust','ark','valheim','minecraft_java','satisfactory')");
        if ($this->isSqlite()) {
            $this->addSql('ALTER TABLE game_templates DROP COLUMN game_key');
            $this->addSql('ALTER TABLE game_templates DROP COLUMN steam_app_id');
            $this->addSql('ALTER TABLE game_templates DROP COLUMN sniper_profile');
            $this->addSql('ALTER TABLE game_templates DROP COLUMN env_vars');
            $this->addSql('ALTER TABLE game_templates DROP COLUMN config_files');
            $this->addSql('ALTER TABLE game_templates DROP COLUMN plugin_paths');
            $this->addSql('ALTER TABLE game_templates DROP COLUMN fastdl_settings');
            $this->addSql('ALTER TABLE game_templates RENAME COLUMN display_name TO name');
        } else {
            $this->addSql('ALTER TABLE game_templates DROP game_key, DROP steam_app_id, DROP sniper_profile, DROP env_vars, DROP config_files, DROP plugin_paths, DROP fastdl_settings');
            $this->addSql('ALTER TABLE game_templates CHANGE display_name name VARCHAR(120) NOT NULL');
        }
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
        $sql = sprintf(
            'INSERT INTO game_templates (game_key, display_name, description, steam_app_id, sniper_profile, required_ports, start_params, env_vars, config_files, plugin_paths, fastdl_settings, install_command, update_command, allowed_switch_flags, created_at, updated_at) '
            . 'SELECT %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s '
            . 'WHERE NOT EXISTS (SELECT 1 FROM game_templates WHERE game_key = %s)',
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
            $this->currentTimestampExpression(),
            $this->currentTimestampExpression(),
            $this->quote($gameKey),
        );

        $this->addSql($sql);
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

    private function isSqlite(): bool
    {
        return $this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\SQLitePlatform;
    }

    private function currentTimestampExpression(): string
    {
        return $this->isSqlite() ? 'CURRENT_TIMESTAMP' : 'NOW()';
    }
}
