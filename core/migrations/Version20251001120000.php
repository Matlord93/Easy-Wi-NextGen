<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251001120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tag OS-specific templates with merged_group in requirements.';
    }

    public function up(Schema $schema): void
    {
        if (!$this->hasColumn('game_templates', 'requirements')) {
            return;
        }

        $templates = $this->connection->fetchAllAssociative(
            'SELECT id, game_key, steam_app_id, requirements, supported_os FROM game_templates'
        );

        $groups = [];
        foreach ($templates as $template) {
            $gameKey = (string) $template['game_key'];
            $steamAppId = $template['steam_app_id'] !== null ? (int) $template['steam_app_id'] : null;
            $os = $this->resolveTemplateOs($gameKey, (string) ($template['supported_os'] ?? '[]'));
            if ($os === null) {
                continue;
            }
            $baseKey = $this->resolveBaseGameKey($gameKey);
            $groupKey = sprintf('%s:%s', $steamAppId ?? 'null', $baseKey);
            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'base_key' => $baseKey,
                    'templates' => [],
                    'os' => [],
                ];
            }
            $groups[$groupKey]['templates'][] = $template;
            $groups[$groupKey]['os'][$os] = true;
        }

        foreach ($groups as $group) {
            if (count($group['templates']) < 2 || count($group['os']) < 2) {
                continue;
            }
            $baseKey = $group['base_key'];
            foreach ($group['templates'] as $template) {
                $requirements = $this->decodeJsonObject((string) $template['requirements']);
                $requirements['merged_group'] = $baseKey;
                $this->addSql(sprintf(
                    'UPDATE game_templates SET requirements = %s WHERE id = %d',
                    $this->quoteJson($requirements),
                    (int) $template['id'],
                ));
            }
        }
    }

    public function down(Schema $schema): void
    {
        if (!$this->hasColumn('game_templates', 'requirements')) {
            return;
        }

        $templates = $this->connection->fetchAllAssociative(
            'SELECT id, requirements FROM game_templates'
        );

        foreach ($templates as $template) {
            $requirements = $this->decodeJsonObject((string) $template['requirements']);
            if (!array_key_exists('merged_group', $requirements)) {
                continue;
            }
            unset($requirements['merged_group']);
            $this->addSql(sprintf(
                'UPDATE game_templates SET requirements = %s WHERE id = %d',
                $this->quoteJson($requirements),
                (int) $template['id'],
            ));
        }
    }

    private function resolveBaseGameKey(string $gameKey): string
    {
        return preg_replace('/_(windows|linux)$/', '', $gameKey) ?? $gameKey;
    }

    private function resolveTemplateOs(string $gameKey, string $supportedOsJson): ?string
    {
        $supportedOs = $this->decodeJsonArray($supportedOsJson);
        if (count($supportedOs) === 1) {
            $value = strtolower((string) $supportedOs[0]);
            if (in_array($value, ['linux', 'windows'], true)) {
                return $value;
            }
        }

        if (str_ends_with($gameKey, '_windows')) {
            return 'windows';
        }
        if (str_ends_with($gameKey, '_linux')) {
            return 'linux';
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function decodeJsonArray(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonObject(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function quoteJson(array $value): string
    {
        return $this->connection->quote($this->jsonEncode($value));
    }

    private function jsonEncode(array $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '{}' : $encoded;
    }

    private function hasColumn(string $table, string $column): bool
    {
        $columns = $this->connection->createSchemaManager()->listTableColumns($table);

        return array_key_exists($column, $columns);
    }
}
