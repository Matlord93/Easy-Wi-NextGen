<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260530000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add RCON port to Minecraft Java templates; add rcon.port to start_params and config_files; include runtime payload in API start/restart.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('game_templates')) {
            return;
        }

        $printf = "printf '%s=%s\\n' \"\$k\" \"\$v\"";
        $setPropertyFn = "set_property() { local f=\"\$1\" k=\"\$2\" v=\"\$3\"; touch \"\$f\"; { grep -v \"^\${k}=\" \"\$f\" 2>/dev/null || true; " . $printf . "; } > \"\${f}.tmp\" && mv \"\${f}.tmp\" \"\$f\"; };";

        // start_params before this migration (set by Version20260529000001)
        $oldStart = $setPropertyFn
            . ' set_property server.properties motd "{{SERVER_NAME}}";'
            . ' set_property server.properties server-port "{{PORT_GAME}}";'
            . ' set_property server.properties max-players "{{MAX_PLAYERS}}";'
            . ' set_property server.properties enable-rcon "true";'
            . ' set_property server.properties rcon.password "{{RCON_PASSWORD}}";'
            . ' set_property server.properties server-password "{{SERVER_PASSWORD}}";'
            . ' {{JAVA_BIN}} -Xms{{JAVA_XMS}} -Xmx{{JAVA_XMX}} -jar {{INSTANCE_DIR}}/server.jar nogui';

        // New start_params with rcon.port inserted after rcon.password
        $newStart = $setPropertyFn
            . ' set_property server.properties motd "{{SERVER_NAME}}";'
            . ' set_property server.properties server-port "{{PORT_GAME}}";'
            . ' set_property server.properties max-players "{{MAX_PLAYERS}}";'
            . ' set_property server.properties enable-rcon "true";'
            . ' set_property server.properties rcon.password "{{RCON_PASSWORD}}";'
            . ' set_property server.properties rcon.port "{{PORT_RCON}}";'
            . ' set_property server.properties server-password "{{SERVER_PASSWORD}}";'
            . ' {{JAVA_BIN}} -Xms{{JAVA_XMS}} -Xmx{{JAVA_XMX}} -jar {{INSTANCE_DIR}}/server.jar nogui';

        foreach (['minecraft_vanilla_all', 'minecraft_paper_all'] as $gameKey) {
            // Update start_params (only if not customised beyond the previous migration's value)
            $this->connection->executeStatement(
                'UPDATE game_templates SET start_params = :new WHERE game_key = :key AND start_params = :old',
                ['new' => $newStart, 'key' => $gameKey, 'old' => $oldStart]
            );

            // Add RCON port to required_ports and rcon.port to config_files server.properties
            $this->updateJavaTemplate($gameKey);
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('game_templates')) {
            return;
        }

        $printf = "printf '%s=%s\\n' \"\$k\" \"\$v\"";
        $setPropertyFn = "set_property() { local f=\"\$1\" k=\"\$2\" v=\"\$3\"; touch \"\$f\"; { grep -v \"^\${k}=\" \"\$f\" 2>/dev/null || true; " . $printf . "; } > \"\${f}.tmp\" && mv \"\${f}.tmp\" \"\$f\"; };";

        $oldStart = $setPropertyFn
            . ' set_property server.properties motd "{{SERVER_NAME}}";'
            . ' set_property server.properties server-port "{{PORT_GAME}}";'
            . ' set_property server.properties max-players "{{MAX_PLAYERS}}";'
            . ' set_property server.properties enable-rcon "true";'
            . ' set_property server.properties rcon.password "{{RCON_PASSWORD}}";'
            . ' set_property server.properties server-password "{{SERVER_PASSWORD}}";'
            . ' {{JAVA_BIN}} -Xms{{JAVA_XMS}} -Xmx{{JAVA_XMX}} -jar {{INSTANCE_DIR}}/server.jar nogui';

        $newStart = $setPropertyFn
            . ' set_property server.properties motd "{{SERVER_NAME}}";'
            . ' set_property server.properties server-port "{{PORT_GAME}}";'
            . ' set_property server.properties max-players "{{MAX_PLAYERS}}";'
            . ' set_property server.properties enable-rcon "true";'
            . ' set_property server.properties rcon.password "{{RCON_PASSWORD}}";'
            . ' set_property server.properties rcon.port "{{PORT_RCON}}";'
            . ' set_property server.properties server-password "{{SERVER_PASSWORD}}";'
            . ' {{JAVA_BIN}} -Xms{{JAVA_XMS}} -Xmx{{JAVA_XMX}} -jar {{INSTANCE_DIR}}/server.jar nogui';

        foreach (['minecraft_vanilla_all', 'minecraft_paper_all'] as $gameKey) {
            $this->connection->executeStatement(
                'UPDATE game_templates SET start_params = :old WHERE game_key = :key AND start_params = :new',
                ['old' => $oldStart, 'key' => $gameKey, 'new' => $newStart]
            );

            $this->revertJavaTemplate($gameKey);
        }
    }

    private function updateJavaTemplate(string $gameKey): void
    {
        $row = $this->connection->fetchAssociative(
            'SELECT required_ports, config_files FROM game_templates WHERE game_key = :key',
            ['key' => $gameKey]
        );
        if ($row === false) {
            return;
        }

        // Add RCON port to required_ports if not already present
        $requiredPorts = json_decode((string) ($row['required_ports'] ?? '[]'), true) ?: [];
        $hasRcon = false;
        foreach ($requiredPorts as $port) {
            if (($port['name'] ?? '') === 'rcon') {
                $hasRcon = true;
                break;
            }
        }
        if (!$hasRcon) {
            $requiredPorts[] = ['name' => 'rcon', 'label' => 'RCON', 'protocol' => 'tcp'];
            $this->connection->executeStatement(
                'UPDATE game_templates SET required_ports = :rp WHERE game_key = :key',
                [
                    'rp' => json_encode($requiredPorts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'key' => $gameKey,
                ]
            );
        }

        // Add rcon.port={{PORT_RCON}} to config_files server.properties if not already present
        $configFiles = json_decode((string) ($row['config_files'] ?? '[]'), true) ?: [];
        foreach ($configFiles as &$file) {
            if (($file['path'] ?? '') !== 'server.properties') {
                continue;
            }
            $contents = (string) ($file['contents'] ?? '');
            if (!str_contains($contents, 'rcon.port=')) {
                $file['contents'] = str_replace(
                    "rcon.password={{RCON_PASSWORD}}\n",
                    "rcon.password={{RCON_PASSWORD}}\nrcon.port={{PORT_RCON}}\n",
                    $contents
                );
            }
        }
        unset($file);
        $this->connection->executeStatement(
            'UPDATE game_templates SET config_files = :cf WHERE game_key = :key',
            [
                'cf' => json_encode($configFiles, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'key' => $gameKey,
            ]
        );
    }

    private function revertJavaTemplate(string $gameKey): void
    {
        $row = $this->connection->fetchAssociative(
            'SELECT required_ports, config_files FROM game_templates WHERE game_key = :key',
            ['key' => $gameKey]
        );
        if ($row === false) {
            return;
        }

        // Remove RCON port from required_ports
        $requiredPorts = json_decode((string) ($row['required_ports'] ?? '[]'), true) ?: [];
        $requiredPorts = array_values(array_filter($requiredPorts, static fn (array $p): bool => ($p['name'] ?? '') !== 'rcon'));
        $this->connection->executeStatement(
            'UPDATE game_templates SET required_ports = :rp WHERE game_key = :key',
            [
                'rp' => json_encode($requiredPorts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'key' => $gameKey,
            ]
        );

        // Remove rcon.port from config_files server.properties
        $configFiles = json_decode((string) ($row['config_files'] ?? '[]'), true) ?: [];
        foreach ($configFiles as &$file) {
            if (($file['path'] ?? '') !== 'server.properties') {
                continue;
            }
            $file['contents'] = str_replace("rcon.port={{PORT_RCON}}\n", '', (string) ($file['contents'] ?? ''));
        }
        unset($file);
        $this->connection->executeStatement(
            'UPDATE game_templates SET config_files = :cf WHERE game_key = :key',
            [
                'cf' => json_encode($configFiles, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'key' => $gameKey,
            ]
        );
    }
}
