<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20260601120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Update CS2 start command and add MAX_PLAYERS env var.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('game_templates')) {
            return;
        }

        $this->addSql(sprintf(
            'UPDATE game_templates SET start_params = %s WHERE game_key = %s',
            $this->quote('/home/installdir/game/cs2.sh -port {{PORT_GAME}} +sv_queryport {{PORT_QUERY}} +rcon_port {{PORT_RCON}} +tv_port {{PORT_TV}} +maxplayers {{MAX_PLAYERS}} +map de_dust2 +sv_setsteamaccount {{STEAM_GSLT}} +hostname "{{SERVER_NAME}}" +rcon_password "{{RCON_PASSWORD}}"'),
            $this->quote('cs2'),
        ));

        $templates = $this->connection->fetchAllAssociative('SELECT id, env_vars FROM game_templates WHERE game_key = ' . $this->quote('cs2'));
        foreach ($templates as $template) {
            $envVars = $this->decodeJsonArray((string) ($template['env_vars'] ?? '[]'));
            $hasMaxPlayers = false;
            foreach ($envVars as $entry) {
                if (is_array($entry) && (string) ($entry['key'] ?? '') === 'MAX_PLAYERS') {
                    $hasMaxPlayers = true;
                    break;
                }
            }
            if (!$hasMaxPlayers) {
                $envVars[] = ['key' => 'MAX_PLAYERS', 'value' => '16'];
                $this->addSql(sprintf(
                    'UPDATE game_templates SET env_vars = %s WHERE id = %d',
                    $this->quoteJson($envVars),
                    (int) $template['id'],
                ));
            }
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('game_templates')) {
            return;
        }

        $this->addSql(sprintf(
            'UPDATE game_templates SET start_params = %s WHERE game_key = %s',
            $this->quote('{{INSTANCE_DIR}}/game/bin/linuxsteamrt64/cs2 -dedicated -console -usercon -tickrate 128 +map de_dust2 +sv_setsteamaccount {{STEAM_GSLT}} +hostname "{{SERVER_NAME}}" +rcon_password "{{RCON_PASSWORD}}"'),
            $this->quote('cs2'),
        ));

        $templates = $this->connection->fetchAllAssociative('SELECT id, env_vars FROM game_templates WHERE game_key = ' . $this->quote('cs2'));
        foreach ($templates as $template) {
            $envVars = $this->decodeJsonArray((string) ($template['env_vars'] ?? '[]'));
            $filtered = [];
            foreach ($envVars as $entry) {
                if (is_array($entry) && (string) ($entry['key'] ?? '') === 'MAX_PLAYERS') {
                    continue;
                }
                $filtered[] = $entry;
            }
            $this->addSql(sprintf(
                'UPDATE game_templates SET env_vars = %s WHERE id = %d',
                $this->quoteJson($filtered),
                (int) $template['id'],
            ));
        }
    }

    private function decodeJsonArray(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
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
}
