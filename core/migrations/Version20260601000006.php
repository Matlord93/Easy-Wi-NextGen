<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260601000006 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add beacon (invite-code) UDP port to Windrose templates; update ServerDescription.json template.';
    }

    public function up(Schema $schema): void
    {
        if (!$this->tableExists('game_templates')) {
            return;
        }

        foreach (['windrose', 'windrose_windows'] as $gameKey) {
            $row = $this->connection->fetchAssociative(
                'SELECT id, required_ports, config_files FROM game_templates WHERE game_key = :key LIMIT 1',
                ['key' => $gameKey],
            );

            if ($row === false) {
                continue;
            }

            $this->updateRequiredPorts((int) $row['id'], (string) $row['required_ports']);
            $this->updateConfigFiles((int) $row['id'], (string) $row['config_files']);
        }
    }

    public function down(Schema $schema): void
    {
        if (!$this->tableExists('game_templates')) {
            return;
        }

        foreach (['windrose', 'windrose_windows'] as $gameKey) {
            $row = $this->connection->fetchAssociative(
                'SELECT id, required_ports, config_files FROM game_templates WHERE game_key = :key LIMIT 1',
                ['key' => $gameKey],
            );

            if ($row === false) {
                continue;
            }

            $this->revertRequiredPorts((int) $row['id'], (string) $row['required_ports']);
            $this->revertConfigFiles((int) $row['id'], (string) $row['config_files']);
        }
    }

    private function updateRequiredPorts(int $id, string $raw): void
    {
        $ports = json_decode($raw, true);
        if (!is_array($ports)) {
            return;
        }

        foreach ($ports as $port) {
            if (is_array($port) && ($port['name'] ?? '') === 'beacon') {
                return;
            }
        }

        $ports[] = ['name' => 'beacon', 'label' => 'Beacon (Invite)', 'protocol' => 'udp'];

        $this->addSql(
            'UPDATE game_templates SET required_ports = :ports WHERE id = :id',
            ['ports' => json_encode($ports, JSON_UNESCAPED_UNICODE), 'id' => $id],
        );
    }

    private function updateConfigFiles(int $id, string $raw): void
    {
        $files = json_decode($raw, true);
        if (!is_array($files)) {
            return;
        }

        $changed = false;
        foreach ($files as &$entry) {
            if (!is_array($entry) || ($entry['path'] ?? '') !== 'R5/ServerDescription.json') {
                continue;
            }
            $contents = (string) ($entry['contents'] ?? '');
            if (str_contains($contents, '"BeaconPort"')) {
                continue;
            }
            $entry['contents'] = str_replace(
                '"DirectConnectionServerPort": {{PORT_GAME}}',
                '"DirectConnectionServerPort": {{PORT_GAME}},' . "\n" . '  "BeaconPort": {{PORT_BEACON}}',
                $contents,
            );
            $changed = true;
        }
        unset($entry);

        if (!$changed) {
            return;
        }

        $this->addSql(
            'UPDATE game_templates SET config_files = :files WHERE id = :id',
            ['files' => json_encode($files, JSON_UNESCAPED_UNICODE), 'id' => $id],
        );
    }

    private function revertRequiredPorts(int $id, string $raw): void
    {
        $ports = json_decode($raw, true);
        if (!is_array($ports)) {
            return;
        }

        $filtered = array_values(array_filter($ports, static fn (mixed $p): bool => is_array($p) && ($p['name'] ?? '') !== 'beacon'));

        if (count($filtered) === count($ports)) {
            return;
        }

        $this->addSql(
            'UPDATE game_templates SET required_ports = :ports WHERE id = :id',
            ['ports' => json_encode($filtered, JSON_UNESCAPED_UNICODE), 'id' => $id],
        );
    }

    private function revertConfigFiles(int $id, string $raw): void
    {
        $files = json_decode($raw, true);
        if (!is_array($files)) {
            return;
        }

        $changed = false;
        foreach ($files as &$entry) {
            if (!is_array($entry) || ($entry['path'] ?? '') !== 'R5/ServerDescription.json') {
                continue;
            }
            $contents = (string) ($entry['contents'] ?? '');
            if (!str_contains($contents, '"BeaconPort"')) {
                continue;
            }
            $entry['contents'] = str_replace(
                '"DirectConnectionServerPort": {{PORT_GAME}},' . "\n" . '  "BeaconPort": {{PORT_BEACON}}',
                '"DirectConnectionServerPort": {{PORT_GAME}}',
                $contents,
            );
            $changed = true;
        }
        unset($entry);

        if (!$changed) {
            return;
        }

        $this->addSql(
            'UPDATE game_templates SET config_files = :files WHERE id = :id',
            ['files' => json_encode($files, JSON_UNESCAPED_UNICODE), 'id' => $id],
        );
    }

    private function tableExists(string $table): bool
    {
        try {
            return (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table',
                ['table' => $table],
            ) > 0;
        } catch (\Throwable) {
            return false;
        }
    }
}
