<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260531000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add separate IPv6 port (gamev6) to minecraft_bedrock template and use PORT_GAMEV6 for server-portv6.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('game_templates')) {
            return;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT required_ports, start_params, config_files FROM game_templates WHERE game_key = :key',
            ['key' => 'minecraft_bedrock']
        );
        if ($row === false) {
            return;
        }

        // Add gamev6 port to required_ports if not already present
        $requiredPorts = json_decode((string) ($row['required_ports'] ?? '[]'), true) ?: [];
        $hasGameV6 = false;
        foreach ($requiredPorts as $port) {
            if (($port['name'] ?? '') === 'gamev6') {
                $hasGameV6 = true;
                break;
            }
        }
        if (!$hasGameV6) {
            // Rename 'Game' label of existing game port for clarity, then append gamev6
            foreach ($requiredPorts as &$port) {
                if (($port['name'] ?? '') === 'game') {
                    $port['label'] = 'Game (IPv4)';
                }
            }
            unset($port);
            $requiredPorts[] = ['name' => 'gamev6', 'label' => 'Game (IPv6)', 'protocol' => 'udp'];
            $this->connection->executeStatement(
                'UPDATE game_templates SET required_ports = :rp WHERE game_key = :key',
                [
                    'rp'  => json_encode($requiredPorts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'key' => 'minecraft_bedrock',
                ]
            );
        }

        // Replace server-portv6 "{{PORT_GAME}}" → "{{PORT_GAMEV6}}" in start_params
        $startParams = (string) ($row['start_params'] ?? '');
        $newStartParams = str_replace(
            'server-portv6 "{{PORT_GAME}}"',
            'server-portv6 "{{PORT_GAMEV6}}"',
            $startParams
        );
        if ($newStartParams !== $startParams) {
            $this->connection->executeStatement(
                'UPDATE game_templates SET start_params = :sp WHERE game_key = :key',
                ['sp' => $newStartParams, 'key' => 'minecraft_bedrock']
            );
        }

        // Replace server-portv6={{PORT_GAME}} → server-portv6={{PORT_GAMEV6}} in config_files
        $configFiles = json_decode((string) ($row['config_files'] ?? '[]'), true) ?: [];
        $cfChanged = false;
        foreach ($configFiles as &$file) {
            if (($file['path'] ?? '') !== 'server.properties') {
                continue;
            }
            $contents = (string) ($file['contents'] ?? '');
            $updated  = str_replace('server-portv6={{PORT_GAME}}', 'server-portv6={{PORT_GAMEV6}}', $contents);
            if ($updated !== $contents) {
                $file['contents'] = $updated;
                $cfChanged = true;
            }
        }
        unset($file);
        if ($cfChanged) {
            $this->connection->executeStatement(
                'UPDATE game_templates SET config_files = :cf WHERE game_key = :key',
                [
                    'cf'  => json_encode($configFiles, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'key' => 'minecraft_bedrock',
                ]
            );
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('game_templates')) {
            return;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT required_ports, start_params, config_files FROM game_templates WHERE game_key = :key',
            ['key' => 'minecraft_bedrock']
        );
        if ($row === false) {
            return;
        }

        // Remove gamev6 port and restore original 'Game' label
        $requiredPorts = json_decode((string) ($row['required_ports'] ?? '[]'), true) ?: [];
        foreach ($requiredPorts as &$port) {
            if (($port['name'] ?? '') === 'game') {
                $port['label'] = 'Game';
            }
        }
        unset($port);
        $requiredPorts = array_values(array_filter($requiredPorts, static fn (array $p): bool => ($p['name'] ?? '') !== 'gamev6'));
        $this->connection->executeStatement(
            'UPDATE game_templates SET required_ports = :rp WHERE game_key = :key',
            [
                'rp'  => json_encode($requiredPorts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'key' => 'minecraft_bedrock',
            ]
        );

        // Revert start_params
        $startParams    = (string) ($row['start_params'] ?? '');
        $revertedParams = str_replace(
            'server-portv6 "{{PORT_GAMEV6}}"',
            'server-portv6 "{{PORT_GAME}}"',
            $startParams
        );
        if ($revertedParams !== $startParams) {
            $this->connection->executeStatement(
                'UPDATE game_templates SET start_params = :sp WHERE game_key = :key',
                ['sp' => $revertedParams, 'key' => 'minecraft_bedrock']
            );
        }

        // Revert config_files
        $configFiles = json_decode((string) ($row['config_files'] ?? '[]'), true) ?: [];
        $cfChanged   = false;
        foreach ($configFiles as &$file) {
            if (($file['path'] ?? '') !== 'server.properties') {
                continue;
            }
            $contents = (string) ($file['contents'] ?? '');
            $reverted = str_replace('server-portv6={{PORT_GAMEV6}}', 'server-portv6={{PORT_GAME}}', $contents);
            if ($reverted !== $contents) {
                $file['contents'] = $reverted;
                $cfChanged        = true;
            }
        }
        unset($file);
        if ($cfChanged) {
            $this->connection->executeStatement(
                'UPDATE game_templates SET config_files = :cf WHERE game_key = :key',
                [
                    'cf'  => json_encode($configFiles, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'key' => 'minecraft_bedrock',
                ]
            );
        }
    }
}
