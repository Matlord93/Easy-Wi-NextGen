<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20261025110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Normalize Source1 template query defaults to same_as_game_port.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('game_templates')) {
            return;
        }

        $table = $schema->getTable('game_templates');
        if (!$table->hasColumn('requirements')) {
            return;
        }

        $templates = $this->connection->fetchAllAssociative('SELECT id, game_key, requirements FROM game_templates');

        $updated = 0;
        $updatedKeys = [];
        foreach ($templates as $template) {
            $requirements = $this->decodeJsonObject((string) ($template['requirements'] ?? '{}'));
            if ($requirements === []) {
                continue;
            }

            if (!$this->isSource1QueryTemplate((string) ($template['game_key'] ?? ''), $requirements)) {
                continue;
            }

            $queryRaw = $requirements['query'] ?? [];
            $query = is_array($queryRaw) ? $queryRaw : [];
            $changed = false;

            $existingBehavior = strtolower(trim((string) ($query['query_port_behavior'] ?? $query['port_behavior'] ?? '')));
            if ($existingBehavior !== 'same_as_game_port') {
                $query['query_port_behavior'] = 'same_as_game_port';
                unset($query['port_behavior']);
                $changed = true;
            }

            if (array_key_exists('port', $query)) {
                unset($query['port']);
                $changed = true;
            }
            if (array_key_exists('query_port', $query)) {
                unset($query['query_port']);
                $changed = true;
            }

            if (!$changed) {
                continue;
            }

            if (!isset($query['type']) || trim((string) $query['type']) === '') {
                $query['type'] = 'steam_a2s';
            }

            $requirements['query'] = $query;
            $this->addSql(
                sprintf('UPDATE game_templates SET requirements = %s WHERE id = %d', $this->quoteJson($requirements), (int) $template['id'])
            );
            $updated++;
            $updatedKeys[] = (string) ($template['game_key'] ?? ('id:' . (string) $template['id']));
        }

        $this->write(sprintf('Source1 query defaults normalized: %d template(s).', $updated));
        if ($updated > 0) {
            $this->write('Templates: ' . implode(', ', $updatedKeys));
        }
    }

    public function down(Schema $schema): void
    {
        // Irreversible data normalization.
    }

    /**
     * @param array<string, mixed> $requirements
     */
    private function isSource1QueryTemplate(string $gameKey, array $requirements): bool
    {
        if ($this->isSource2Template($gameKey, $requirements)) {
            return false;
        }

        $queryRaw = $requirements['query'] ?? null;
        $query = is_array($queryRaw) ? $queryRaw : [];
        $rawProtocol = $query['type']
            ?? $query['protocol']
            ?? $query['query_protocol']
            ?? $requirements['query_type']
            ?? $queryRaw;

        $protocol = strtolower(trim((string) $rawProtocol));

        return in_array($protocol, ['steam_a2s', 'source', 'valve', 'a2s', 'source1', 'source_1', 'source-1'], true);
    }

    /**
     * @param array<string, mixed> $requirements
     */
    private function isSource2Template(string $gameKey, array $requirements): bool
    {
        $normalizedKey = strtolower(trim($gameKey));
        if ($normalizedKey === 'cs2' || str_starts_with($normalizedKey, 'cs2_') || str_contains($normalizedKey, '_cs2') || str_contains($normalizedKey, 'source2')) {
            return true;
        }

        $queryRaw = $requirements['query'] ?? null;
        $query = is_array($queryRaw) ? $queryRaw : [];
        foreach (['engine', 'source_engine', 'family'] as $key) {
            $value = strtolower(trim((string) ($query[$key] ?? '')));
            if ($value === 'source2' || $value === 'source_2' || $value === 'source-2') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonObject(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function quoteJson(array $value): string
    {
        try {
            $json = json_encode($value, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Failed to encode JSON payload.', 0, $exception);
        }

        return $this->connection->quote($json);
    }
}
