<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260529000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Update Minecraft templates to write server.properties on every start via set_property preamble.';
    }

    public function up(Schema $schema): void
    {
        $printf = "printf '%s=%s\\n' \"\$k\" \"\$v\"";
        $setPropertyFn = "set_property() { local f=\"\$1\" k=\"\$2\" v=\"\$3\"; touch \"\$f\"; { grep -v \"^\${k}=\" \"\$f\" 2>/dev/null || true; " . $printf . "; } > \"\${f}.tmp\" && mv \"\${f}.tmp\" \"\$f\"; };";

        $javaOldStart = '{{JAVA_BIN}} -Xms{{JAVA_XMS}} -Xmx{{JAVA_XMX}} -jar {{INSTANCE_DIR}}/server.jar nogui';
        $javaNewStart = $setPropertyFn
            . ' set_property server.properties motd "{{SERVER_NAME}}";'
            . ' set_property server.properties server-port "{{PORT_GAME}}";'
            . ' set_property server.properties max-players "{{MAX_PLAYERS}}";'
            . ' set_property server.properties enable-rcon "true";'
            . ' set_property server.properties rcon.password "{{RCON_PASSWORD}}";'
            . ' set_property server.properties server-password "{{SERVER_PASSWORD}}";'
            . ' {{JAVA_BIN}} -Xms{{JAVA_XMS}} -Xmx{{JAVA_XMX}} -jar {{INSTANCE_DIR}}/server.jar nogui';

        $bedrockOldStart = '{{INSTANCE_DIR}}/bedrock_server';
        $bedrockNewStart = $setPropertyFn
            . ' set_property server.properties server-name "{{SERVER_NAME}}";'
            . ' set_property server.properties server-port "{{PORT_GAME}}";'
            . ' set_property server.properties server-portv6 "{{PORT_GAME}}";'
            . ' set_property server.properties max-players "{{MAX_PLAYERS}}";'
            . ' set_property server.properties server-password "{{SERVER_PASSWORD}}";'
            . ' set_property server.properties online-mode "true";'
            . ' {{INSTANCE_DIR}}/bedrock_server';

        // Update start_params for Java templates (only if not already customized)
        $this->connection->executeStatement(
            'UPDATE game_templates SET start_params = :new WHERE game_key = :key AND start_params = :old',
            ['new' => $javaNewStart, 'key' => 'minecraft_vanilla_all', 'old' => $javaOldStart]
        );
        $this->connection->executeStatement(
            'UPDATE game_templates SET start_params = :new WHERE game_key = :key AND start_params = :old',
            ['new' => $javaNewStart, 'key' => 'minecraft_paper_all', 'old' => $javaOldStart]
        );

        // Update start_params for Bedrock (only if not already customized)
        $this->connection->executeStatement(
            'UPDATE game_templates SET start_params = :new WHERE game_key = :key AND start_params = :old',
            ['new' => $bedrockNewStart, 'key' => 'minecraft_bedrock', 'old' => $bedrockOldStart]
        );

        // Update config_files for Vanilla: change server-port={{SERVER_PORT}} to server-port={{PORT_GAME}}
        $this->updateJavaConfigFiles('minecraft_vanilla_all');

        // Update config_files for Paper: same fix
        $this->updateJavaConfigFiles('minecraft_paper_all');

        // Update config_files for Bedrock: fix portv6, add server-password, fix max-players placeholder
        $this->updateBedrockConfigFiles();

        // Update env_vars for Bedrock: add MAX_PLAYERS if missing
        $this->addBedrockMaxPlayersEnvVar();
    }

    public function down(Schema $schema): void
    {
        $printf = "printf '%s=%s\\n' \"\$k\" \"\$v\"";
        $setPropertyFn = "set_property() { local f=\"\$1\" k=\"\$2\" v=\"\$3\"; touch \"\$f\"; { grep -v \"^\${k}=\" \"\$f\" 2>/dev/null || true; " . $printf . "; } > \"\${f}.tmp\" && mv \"\${f}.tmp\" \"\$f\"; };";

        $javaOldStart = '{{JAVA_BIN}} -Xms{{JAVA_XMS}} -Xmx{{JAVA_XMX}} -jar {{INSTANCE_DIR}}/server.jar nogui';
        $javaNewStart = $setPropertyFn
            . ' set_property server.properties motd "{{SERVER_NAME}}";'
            . ' set_property server.properties server-port "{{PORT_GAME}}";'
            . ' set_property server.properties max-players "{{MAX_PLAYERS}}";'
            . ' set_property server.properties enable-rcon "true";'
            . ' set_property server.properties rcon.password "{{RCON_PASSWORD}}";'
            . ' set_property server.properties server-password "{{SERVER_PASSWORD}}";'
            . ' {{JAVA_BIN}} -Xms{{JAVA_XMS}} -Xmx{{JAVA_XMX}} -jar {{INSTANCE_DIR}}/server.jar nogui';

        $bedrockOldStart = '{{INSTANCE_DIR}}/bedrock_server';
        $bedrockNewStart = $setPropertyFn
            . ' set_property server.properties server-name "{{SERVER_NAME}}";'
            . ' set_property server.properties server-port "{{PORT_GAME}}";'
            . ' set_property server.properties server-portv6 "{{PORT_GAME}}";'
            . ' set_property server.properties max-players "{{MAX_PLAYERS}}";'
            . ' set_property server.properties server-password "{{SERVER_PASSWORD}}";'
            . ' set_property server.properties online-mode "true";'
            . ' {{INSTANCE_DIR}}/bedrock_server';

        // Revert start_params (only if not further customized)
        $this->connection->executeStatement(
            'UPDATE game_templates SET start_params = :old WHERE game_key = :key AND start_params = :new',
            ['old' => $javaOldStart, 'key' => 'minecraft_vanilla_all', 'new' => $javaNewStart]
        );
        $this->connection->executeStatement(
            'UPDATE game_templates SET start_params = :old WHERE game_key = :key AND start_params = :new',
            ['old' => $javaOldStart, 'key' => 'minecraft_paper_all', 'new' => $javaNewStart]
        );
        $this->connection->executeStatement(
            'UPDATE game_templates SET start_params = :old WHERE game_key = :key AND start_params = :new',
            ['old' => $bedrockOldStart, 'key' => 'minecraft_bedrock', 'new' => $bedrockNewStart]
        );

        // Revert config_files for Java: change server-port={{PORT_GAME}} back to server-port={{SERVER_PORT}}
        foreach (['minecraft_vanilla_all', 'minecraft_paper_all'] as $key) {
            $row = $this->connection->fetchAssociative(
                'SELECT config_files FROM game_templates WHERE game_key = :key',
                ['key' => $key]
            );
            if ($row === false) {
                continue;
            }
            $files = json_decode((string) ($row['config_files'] ?? '[]'), true) ?: [];
            foreach ($files as &$file) {
                if (($file['path'] ?? '') === 'server.properties') {
                    $file['contents'] = str_replace(
                        'server-port={{PORT_GAME}}',
                        'server-port={{SERVER_PORT}}',
                        (string) ($file['contents'] ?? '')
                    );
                }
            }
            unset($file);
            $this->connection->executeStatement(
                'UPDATE game_templates SET config_files = :cf WHERE game_key = :key',
                ['cf' => json_encode($files, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'key' => $key]
            );
        }

        // Revert Bedrock config_files
        $row = $this->connection->fetchAssociative(
            'SELECT config_files FROM game_templates WHERE game_key = :key',
            ['key' => 'minecraft_bedrock']
        );
        if ($row !== false) {
            $files = json_decode((string) ($row['config_files'] ?? '[]'), true) ?: [];
            foreach ($files as &$file) {
                if (($file['path'] ?? '') === 'server.properties') {
                    $contents = (string) ($file['contents'] ?? '');
                    $contents = str_replace('server-portv6={{PORT_GAME}}', 'server-portv6=19133', $contents);
                    $contents = str_replace('max-players={{MAX_PLAYERS}}', 'max-players=10', $contents);
                    $contents = str_replace("server-password={{SERVER_PASSWORD}}\n", '', $contents);
                    $file['contents'] = $contents;
                }
            }
            unset($file);
            $this->connection->executeStatement(
                'UPDATE game_templates SET config_files = :cf WHERE game_key = :key',
                ['cf' => json_encode($files, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'key' => 'minecraft_bedrock']
            );
        }

        // Revert Bedrock env_vars: remove MAX_PLAYERS
        $row = $this->connection->fetchAssociative(
            'SELECT env_vars FROM game_templates WHERE game_key = :key',
            ['key' => 'minecraft_bedrock']
        );
        if ($row !== false) {
            $envVars = json_decode((string) ($row['env_vars'] ?? '[]'), true) ?: [];
            $envVars = array_values(array_filter($envVars, static fn (array $v): bool => ($v['key'] ?? '') !== 'MAX_PLAYERS'));
            $this->connection->executeStatement(
                'UPDATE game_templates SET env_vars = :ev WHERE game_key = :key',
                ['ev' => json_encode($envVars, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'key' => 'minecraft_bedrock']
            );
        }
    }

    private function updateJavaConfigFiles(string $gameKey): void
    {
        $row = $this->connection->fetchAssociative(
            'SELECT config_files FROM game_templates WHERE game_key = :key',
            ['key' => $gameKey]
        );
        if ($row === false) {
            return;
        }
        $files = json_decode((string) ($row['config_files'] ?? '[]'), true) ?: [];
        foreach ($files as &$file) {
            if (($file['path'] ?? '') === 'server.properties') {
                $file['contents'] = str_replace(
                    'server-port={{SERVER_PORT}}',
                    'server-port={{PORT_GAME}}',
                    (string) ($file['contents'] ?? '')
                );
            }
        }
        unset($file);
        $this->connection->executeStatement(
            'UPDATE game_templates SET config_files = :cf WHERE game_key = :key',
            ['cf' => json_encode($files, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'key' => $gameKey]
        );
    }

    private function updateBedrockConfigFiles(): void
    {
        $row = $this->connection->fetchAssociative(
            'SELECT config_files FROM game_templates WHERE game_key = :key',
            ['key' => 'minecraft_bedrock']
        );
        if ($row === false) {
            return;
        }
        $files = json_decode((string) ($row['config_files'] ?? '[]'), true) ?: [];
        foreach ($files as &$file) {
            if (($file['path'] ?? '') === 'server.properties') {
                $contents = (string) ($file['contents'] ?? '');
                // Fix portv6 hardcoded value
                $contents = str_replace('server-portv6=19133', 'server-portv6={{PORT_GAME}}', $contents);
                // Fix max-players hardcoded value
                $contents = str_replace('max-players=10', 'max-players={{MAX_PLAYERS}}', $contents);
                // Add server-password if not present
                if (!str_contains($contents, 'server-password=')) {
                    $contents = str_replace(
                        'online-mode=true',
                        "server-password={{SERVER_PASSWORD}}\nonline-mode=true",
                        $contents
                    );
                }
                $file['contents'] = $contents;
            }
        }
        unset($file);
        $this->connection->executeStatement(
            'UPDATE game_templates SET config_files = :cf WHERE game_key = :key',
            ['cf' => json_encode($files, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'key' => 'minecraft_bedrock']
        );
    }

    private function addBedrockMaxPlayersEnvVar(): void
    {
        $row = $this->connection->fetchAssociative(
            'SELECT env_vars FROM game_templates WHERE game_key = :key',
            ['key' => 'minecraft_bedrock']
        );
        if ($row === false) {
            return;
        }
        $envVars = json_decode((string) ($row['env_vars'] ?? '[]'), true) ?: [];
        // Only add if MAX_PLAYERS is not already present
        $hasMaxPlayers = false;
        foreach ($envVars as $var) {
            if (($var['key'] ?? '') === 'MAX_PLAYERS') {
                $hasMaxPlayers = true;
                break;
            }
        }
        if (!$hasMaxPlayers) {
            // Insert MAX_PLAYERS after SERVER_NAME
            $newEnvVars = [];
            foreach ($envVars as $var) {
                $newEnvVars[] = $var;
                if (($var['key'] ?? '') === 'SERVER_NAME') {
                    $newEnvVars[] = ['key' => 'MAX_PLAYERS', 'value' => '10'];
                }
            }
            $this->connection->executeStatement(
                'UPDATE game_templates SET env_vars = :ev WHERE game_key = :key',
                ['ev' => json_encode($newEnvVars, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'key' => 'minecraft_bedrock']
            );
        }
    }
}
