<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20260620120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Update CS2 server.cfg schema details and maxplayers flag.';
    }

    public function up(Schema $schema): void
    {
        $this->updateCs2Templates($schema);
        $this->updateCs2ServerCfgSchemas($schema);
    }

    public function down(Schema $schema): void
    {
        // No-op: reverting would require restoring previous schema JSON and template params.
    }

    private function updateCs2Templates(Schema $schema): void
    {
        if (!$schema->hasTable('game_templates')) {
            return;
        }

        $startParams = '{{INSTANCE_DIR}}/game/cs2.sh -dedicated +ip 0.0.0.0 -port {{PORT_GAME}} -maxplayers {{MAX_PLAYERS}} +map {{MAP}} -tickrate {{TICKRATE}} +servercfgfile server.cfg -condebug +sv_logfile 1 +game_type {{GAME_TYPE}} +game_mode {{GAME_MODE}} +sv_setsteamaccount {{STEAM_GSLT}}';
        $startParamsWindows = '{{INSTANCE_DIR}}/game/bin/win64/cs2.exe -dedicated +ip 0.0.0.0 -port {{PORT_GAME}} -maxplayers {{MAX_PLAYERS}} +map {{MAP}} -tickrate {{TICKRATE}} +servercfgfile server.cfg -condebug +sv_logfile 1 +game_type {{GAME_TYPE}} +game_mode {{GAME_MODE}} +sv_setsteamaccount {{STEAM_GSLT}}';

        $configFiles = [
            [
                'path' => 'game/csgo/cfg/server.cfg',
                'description' => 'Base server configuration',
                'contents' => "hostname \"{{SERVER_NAME}}\"\nrcon_password \"{{RCON_PASSWORD}}\"\nsv_password \"{{SERVER_PASSWORD}}\"                // optional: Passwort für Spieler (leer = öffentlich)\ngame_type {{GAME_TYPE}}\ngame_mode {{GAME_MODE}}\n",
            ],
        ];

        $allowedSwitchFlags = ['+map', '-maxplayers', '+game_type', '+game_mode'];

        $this->updateTemplate('cs2', $startParams, $configFiles, $allowedSwitchFlags);
        $this->updateTemplate('cs2_windows', $startParamsWindows, $configFiles, $allowedSwitchFlags);
    }

    private function updateCs2ServerCfgSchemas(Schema $schema): void
    {
        if (!$schema->hasTable('game_definitions') || !$schema->hasTable('config_schemas')) {
            return;
        }

        $schemaPayload = $this->buildCs2ServerCfgSchema();
        $filePath = 'game/csgo/cfg/server.cfg';

        foreach (['cs2' => 'Counter-Strike 2', 'cs2_windows' => 'Counter-Strike 2 (Windows)'] as $gameKey => $displayName) {
            $gameDefinitionId = $this->connection->fetchOne(
                'SELECT id FROM game_definitions WHERE game_key = ?',
                [$gameKey],
            );

            if (!$gameDefinitionId) {
                $this->connection->executeStatement(
                    'INSERT INTO game_definitions (game_key, display_name, description, created_at, updated_at) VALUES (?, ?, NULL, NOW(), NOW())',
                    [$gameKey, $displayName],
                );
                $gameDefinitionId = $this->connection->fetchOne(
                    'SELECT id FROM game_definitions WHERE game_key = ?',
                    [$gameKey],
                );
            }

            if (!$gameDefinitionId) {
                continue;
            }

            $existing = $this->connection->fetchAssociative(
                'SELECT id, config_key FROM config_schemas WHERE game_definition_id = ? AND file_path = ?',
                [$gameDefinitionId, $filePath],
            );

            if (is_array($existing) && isset($existing['id'])) {
                $this->addSql(sprintf(
                    'UPDATE config_schemas SET name = %s, format = %s, schema = %s WHERE id = %d',
                    $this->quote('Server.cfg'),
                    $this->quote('convar'),
                    $this->quoteJson($schemaPayload),
                    (int) $existing['id'],
                ));
            } else {
                $this->addSql(sprintf(
                    'INSERT INTO config_schemas (game_definition_id, config_key, name, format, file_path, schema, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s, NOW(), NOW())',
                    (int) $gameDefinitionId,
                    $this->quote('server_cfg'),
                    $this->quote('Server.cfg'),
                    $this->quote('convar'),
                    $this->quote($filePath),
                    $this->quoteJson($schemaPayload),
                ));
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCs2ServerCfgSchema(): array
    {
        return [
            'description' => 'Steuert grundlegende CS2-Serveroptionen wie Hostname, Passwörter, Spielmodus und Matchregeln. Änderungen werden direkt in der server.cfg gespeichert.',
            'offline_edit' => true,
            'fields' => [
                [
                    'key' => 'hostname',
                    'label' => 'Hostname',
                    'type' => 'string',
                    'description' => 'Angezeigter Servername in der Serverliste.',
                    'default' => '',
                ],
                [
                    'key' => 'rcon_password',
                    'label' => 'RCON-Passwort',
                    'type' => 'string',
                    'description' => 'Passwort für Remote-Konsole (RCON).',
                    'default' => '',
                ],
                [
                    'key' => 'sv_password',
                    'label' => 'Serverpasswort',
                    'type' => 'string',
                    'description' => 'Optionales Passwort für Spieler; leer = öffentlich.',
                    'default' => '',
                ],
                [
                    'key' => 'game_type',
                    'label' => 'Game Type',
                    'type' => 'int',
                    'description' => 'Modus-Gruppe (z. B. 0 = Classic).',
                    'default' => 0,
                ],
                [
                    'key' => 'game_mode',
                    'label' => 'Game Mode',
                    'type' => 'int',
                    'description' => 'Modus innerhalb der Gruppe (z. B. 0 = Competitive).',
                    'default' => 0,
                ],
                [
                    'key' => 'sv_lan',
                    'label' => 'LAN-Modus',
                    'type' => 'bool',
                    'description' => '1 = nur LAN, 0 = öffentlich.',
                    'default' => 0,
                ],
                [
                    'key' => 'sv_cheats',
                    'label' => 'Cheats',
                    'type' => 'bool',
                    'description' => 'Cheats erlauben (1 = an, 0 = aus).',
                    'default' => 0,
                ],
                [
                    'key' => 'sv_tags',
                    'label' => 'Server-Tags',
                    'type' => 'string',
                    'description' => 'Kommagetrennte Tags für die Serverliste.',
                    'default' => '',
                ],
                [
                    'key' => 'sv_region',
                    'label' => 'Region',
                    'type' => 'int',
                    'description' => 'Region-Index (z. B. 255 = weltweit).',
                    'default' => 255,
                ],
                [
                    'key' => 'sv_hibernate_when_empty',
                    'label' => 'Hibernate bei leerem Server',
                    'type' => 'bool',
                    'description' => 'Pausiert den Server, wenn keine Spieler online sind (1 = an, 0 = aus).',
                    'default' => 0,
                ],
                [
                    'key' => 'mp_autoteambalance',
                    'label' => 'Auto-Teambalance',
                    'type' => 'bool',
                    'description' => 'Teams automatisch ausgleichen (1 = an, 0 = aus).',
                    'default' => 1,
                ],
                [
                    'key' => 'mp_limitteams',
                    'label' => 'Team-Differenz',
                    'type' => 'int',
                    'description' => 'Maximale Differenz zwischen Teams (z. B. 2).',
                    'default' => 2,
                ],
                [
                    'key' => 'mp_freezetime',
                    'label' => 'Freeze-Time',
                    'type' => 'int',
                    'description' => 'Sekunden vor Rundenstart (z. B. 15).',
                    'default' => 15,
                ],
                [
                    'key' => 'mp_maxrounds',
                    'label' => 'Max. Runden',
                    'type' => 'int',
                    'description' => 'Maximale Rundenzahl pro Match.',
                    'default' => 24,
                ],
                [
                    'key' => 'mp_roundtime',
                    'label' => 'Rundenzeit',
                    'type' => 'float',
                    'description' => 'Rundenzeit in Minuten (z. B. 1.92).',
                    'default' => 1.92,
                ],
                [
                    'key' => 'mp_roundtime_defuse',
                    'label' => 'Rundenzeit Defuse',
                    'type' => 'float',
                    'description' => 'Rundenzeit (Defuse) in Minuten.',
                    'default' => 1.92,
                ],
                [
                    'key' => 'mp_startmoney',
                    'label' => 'Startgeld',
                    'type' => 'int',
                    'description' => 'Startgeld pro Spieler.',
                    'default' => 800,
                ],
                [
                    'key' => 'mp_friendlyfire',
                    'label' => 'Friendly Fire',
                    'type' => 'bool',
                    'description' => 'Eigenbeschuss erlauben (1 = an, 0 = aus).',
                    'default' => 0,
                ],
                [
                    'key' => 'bot_quota',
                    'label' => 'Bot-Anzahl',
                    'type' => 'int',
                    'description' => 'Anzahl der Bots (0 = aus).',
                    'default' => 0,
                ],
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $configFiles
     * @param array<int, string> $allowedSwitchFlags
     */
    private function updateTemplate(string $gameKey, string $startParams, array $configFiles, array $allowedSwitchFlags): void
    {
        $this->addSql(sprintf(
            'UPDATE game_templates SET start_params = %s, config_files = %s, allowed_switch_flags = %s WHERE game_key = %s',
            $this->quote($startParams),
            $this->quoteJson($configFiles),
            $this->quoteJson($allowedSwitchFlags),
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
        return $this->quote(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
