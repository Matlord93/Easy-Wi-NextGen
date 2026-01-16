<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250401120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Minecraft version catalog and install resolver metadata.';
    }

    public function up(Schema $schema): void
    {
        if ($this->isSqlite()) {
            $this->addSql('CREATE TABLE minecraft_versions_catalog (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, channel VARCHAR(20) NOT NULL, mc_version VARCHAR(32) NOT NULL, build VARCHAR(32) DEFAULT NULL, download_url CLOB NOT NULL, sha256 VARCHAR(64) DEFAULT NULL, released_at DATETIME DEFAULT NULL, UNIQUE (channel, mc_version, build))');
            $this->addSql('ALTER TABLE game_templates ADD COLUMN install_resolver JSON DEFAULT NULL');
        } else {
            $this->addSql('CREATE TABLE minecraft_versions_catalog (id INT AUTO_INCREMENT NOT NULL, channel VARCHAR(20) NOT NULL, mc_version VARCHAR(32) NOT NULL, build VARCHAR(32) DEFAULT NULL, download_url LONGTEXT NOT NULL, sha256 VARCHAR(64) DEFAULT NULL, released_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_minecraft_versions_catalog (channel, mc_version, build), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE game_templates ADD install_resolver JSON DEFAULT NULL');
        }

        $this->addSql("UPDATE game_templates SET install_resolver = '[]' WHERE install_resolver IS NULL");

        if (!$this->isSqlite()) {
            $this->addSql('ALTER TABLE game_templates MODIFY install_resolver JSON NOT NULL');
        }

        $this->insertTemplate(
            'minecraft_vanilla_all',
            'Minecraft Java (Vanilla)',
            'Vanilla Minecraft with selectable versions via catalog.',
            null,
            null,
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'tcp'],
            ],
            'java -Xms{{JAVA_XMS}} -Xmx{{JAVA_XMX}} -jar {{INSTANCE_DIR}}/server.jar nogui',
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
            'echo "Install handled by catalog resolver."',
            'echo "Update handled by catalog resolver."',
            [
                'type' => 'minecraft_vanilla',
            ],
            [],
        );

        $this->insertTemplate(
            'minecraft_paper_all',
            'Minecraft Java (Paper)',
            'PaperMC Minecraft with selectable versions/builds via catalog.',
            null,
            null,
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'tcp'],
            ],
            'java -Xms{{JAVA_XMS}} -Xmx{{JAVA_XMX}} -jar {{INSTANCE_DIR}}/server.jar nogui',
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
            'echo "Install handled by catalog resolver."',
            'echo "Update handled by catalog resolver."',
            [
                'type' => 'papermc_paper',
            ],
            [],
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM game_templates WHERE game_key IN ('minecraft_vanilla_all', 'minecraft_paper_all')");

        if ($this->isSqlite()) {
            $this->addSql('DROP TABLE minecraft_versions_catalog');
            $this->addSql('ALTER TABLE game_templates DROP COLUMN install_resolver');
        } else {
            $this->addSql('DROP TABLE minecraft_versions_catalog');
            $this->addSql('ALTER TABLE game_templates DROP install_resolver');
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
        array $installResolver,
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

        if ($this->hasColumn('game_templates', 'install_resolver')) {
            $columns[] = 'install_resolver';
            $values[] = $this->quoteJson($installResolver);
        }

        if ($this->hasColumn('game_templates', 'supported_os')) {
            $columns[] = 'supported_os';
            $columns[] = 'port_profile';
            $columns[] = 'requirements';
            $values[] = $this->quoteJson(['linux', 'windows']);
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

        return [
            'required_vars' => $envVarKeys,
            'required_secrets' => [],
            'steam_install_mode' => $this->resolveSteamInstallMode($gameKey, $steamAppId),
            'customer_allowed_vars' => $envVarKeys,
            'customer_allowed_secrets' => [],
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
            'minecraft_paper_all',
            'minecraft_vanilla_all',
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
