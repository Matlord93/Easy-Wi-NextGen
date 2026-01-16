<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250329120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add template OS support, port profiles, and requirements metadata.';
    }

    public function up(Schema $schema): void
    {
        if ($this->isSqlite()) {
            $this->addSql('ALTER TABLE game_templates ADD COLUMN supported_os JSON DEFAULT NULL');
            $this->addSql('ALTER TABLE game_templates ADD COLUMN port_profile JSON DEFAULT NULL');
            $this->addSql('ALTER TABLE game_templates ADD COLUMN requirements JSON DEFAULT NULL');
        } else {
            $this->addSql('ALTER TABLE game_templates ADD supported_os JSON DEFAULT NULL, ADD port_profile JSON DEFAULT NULL, ADD requirements JSON DEFAULT NULL');
        }

        $this->addSql("UPDATE game_templates SET supported_os = '[]' WHERE supported_os IS NULL");
        $this->addSql("UPDATE game_templates SET port_profile = '[]' WHERE port_profile IS NULL");
        $this->addSql("UPDATE game_templates SET requirements = '{}' WHERE requirements IS NULL");

        if (!$this->isSqlite()) {
            $this->addSql('ALTER TABLE game_templates MODIFY supported_os JSON NOT NULL');
            $this->addSql('ALTER TABLE game_templates MODIFY port_profile JSON NOT NULL');
            $this->addSql('ALTER TABLE game_templates MODIFY requirements JSON NOT NULL');
        }

        $templates = $this->connection->fetchAllAssociative('SELECT id, game_key, steam_app_id, required_ports, env_vars FROM game_templates');

        foreach ($templates as $template) {
            $gameKey = (string) ($template['game_key'] ?? '');
            $steamAppId = $template['steam_app_id'] !== null ? (int) $template['steam_app_id'] : null;
            $requiredPorts = $this->decodeJsonArray((string) ($template['required_ports'] ?? '[]'));
            $envVars = $this->decodeJsonArray((string) ($template['env_vars'] ?? '[]'));

            $supportedOs = str_ends_with($gameKey, '_windows') ? ['windows'] : ['linux'];
            $portProfile = $this->buildPortProfile($requiredPorts);
            $requirements = $this->buildRequirements($gameKey, $steamAppId, $envVars);

            $this->addSql(sprintf(
                'UPDATE game_templates SET supported_os = %s, port_profile = %s, requirements = %s WHERE id = %d',
                $this->quoteJson($supportedOs),
                $this->quoteJson($portProfile),
                $this->quoteJson($requirements),
                (int) $template['id'],
            ));
        }
    }

    public function down(Schema $schema): void
    {
        if ($this->isSqlite()) {
            $this->addSql('ALTER TABLE game_templates DROP COLUMN supported_os');
            $this->addSql('ALTER TABLE game_templates DROP COLUMN port_profile');
            $this->addSql('ALTER TABLE game_templates DROP COLUMN requirements');
        } else {
            $this->addSql('ALTER TABLE game_templates DROP supported_os, DROP port_profile, DROP requirements');
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function decodeJsonArray(string $value): array
    {
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
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
        $platform = $this->connection->getDatabasePlatform();

        if (method_exists($platform, 'getName')) {
            return in_array($platform->getName(), ['sqlite', 'sqlite3'], true);
        }

        return $platform instanceof \Doctrine\DBAL\Platforms\SqlitePlatform
            || $platform instanceof \Doctrine\DBAL\Platforms\SQLitePlatform;
    }
}
