<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application\Backup;

use App\Infrastructure\Config\JsonArray;
use App\Infrastructure\Config\JsonMap;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ArrayParameterType;

/** Exact, same-instance disaster recovery, independent of customer configuration imports. */
final class MusicbotFullState
{
    /** Dependency order, with immutable, application-owned SQL scope predicates. */
    private const SCOPES = [
        'musicbot_tracks' => 'instance_id = :instance',
        'musicbot_playlists' => 'instance_id = :instance',
        'musicbot_radio_stations' => 'instance_id = :instance',
        'musicbot_plugins' => 'instance_id = :instance',
        'musicbot_connections' => 'musicbot_instance_id = :instance',
        'musicbot_roles' => 'instance_id = :instance',
        'musicbot_workflows' => 'instance_id = :instance',
        'musicbot_playlist_items' => 'playlist_id IN (SELECT id FROM musicbot_playlists WHERE instance_id = :instance)',
        'musicbot_queue_items' => 'instance_id = :instance',
        'musicbot_autodj_settings' => 'instance_id = :instance',
        'musicbot_plugin_logs' => 'instance_id = :instance',
        'musicbot_radio_history' => 'instance_id = :instance',
        'musicbot_radio_favorites' => 'station_id IN (SELECT id FROM musicbot_radio_stations WHERE instance_id = :instance)',
        'musicbot_role_assignments' => 'role_id IN (SELECT id FROM musicbot_roles WHERE instance_id = :instance)',
        'musicbot_workflow_conditions' => 'workflow_id IN (SELECT id FROM musicbot_workflows WHERE instance_id = :instance)',
        'musicbot_workflow_actions' => 'workflow_id IN (SELECT id FROM musicbot_workflows WHERE instance_id = :instance)',
        'musicbot_workflow_executions' => 'workflow_id IN (SELECT id FROM musicbot_workflows WHERE instance_id = :instance)',
        'musicbot_schedules' => 'instance_id = :instance',
        'musicbot_stream_settings' => 'instance_id = :instance',
        'musicbot_runtime_events' => 'musicbot_instance_id = :instance',
        'musicbot_customer_limits' => 'customer_id = :customer',
    ];
    private const INSTANCE_COLUMNS = ['name', 'cpu_limit', 'ram_limit', 'disk_limit', 'instance_config'];
    private const VOLATILE_KEYS = ['control_sock', 'runtime_socket', 'tmp_path', 'log_path', 'pid_file', 'runtime_control_token', 'internal_api_key'];

    public function __construct(private readonly Connection $connection, private readonly MusicbotArchiveStreams $streams = new MusicbotArchiveStreams())
    {
    }

    /** @return array<string, mixed> */
    public function export(MusicbotInstance $instance): array
    {
        $tables = [];
        foreach (self::SCOPES as $table => $scope) {
            $tables[$table] = $this->rows($table, $scope, $instance);
        }
        $config = $instance->getInstanceConfig();
        foreach (self::VOLATILE_KEYS as $key) {
            unset($config[$key]);
        }

        return ['instance' => [
            'name' => $instance->getName(), 'cpu_limit' => $instance->getCpuLimit(),
            'ram_limit' => $instance->getRamLimit(), 'disk_limit' => $instance->getDiskLimit(),
            'instance_config' => json_encode($config, JSON_THROW_ON_ERROR),
        ], 'tables' => $tables];
    }

    /** Validate every table, column, row ownership and foreign reference before live changes.
     * @param array<string, mixed> $state
     */
    public function validate(MusicbotInstance $instance, array $state): void
    {
        $tables = JsonMap::fromValue($state['tables'] ?? null) ?? [];
        if (array_keys($tables) !== array_keys(self::SCOPES)) {
            throw new \InvalidArgumentException('Full Musicbot state has an incomplete or unknown table inventory.');
        }
        $instanceState = JsonMap::fromValue($state['instance'] ?? null) ?? [];
        if (array_keys($instanceState) !== self::INSTANCE_COLUMNS) {
            throw new \InvalidArgumentException('Invalid full Musicbot instance settings.');
        }
        foreach (['cpu_limit', 'ram_limit', 'disk_limit'] as $limit) {
            if (!is_int($instanceState[$limit]) || $instanceState[$limit] < 0) {
                throw new \InvalidArgumentException('Invalid full Musicbot resource limit.');
            }
        }
        if (!is_string($instanceState['name']) || !is_string($instanceState['instance_config'])) {
            throw new \InvalidArgumentException('Invalid full Musicbot instance configuration.');
        }
        $config = JsonMap::decode($instanceState['instance_config']);
        if ($config === null || array_intersect(array_keys($config), self::VOLATILE_KEYS) !== []) {
            throw new \InvalidArgumentException('Full Musicbot configuration contains volatile runtime state.');
        }
        $schema = $this->connection->createSchemaManager();
        $ids = [];
        $userIds = [];
        foreach (self::SCOPES as $table => $scope) {
            $rows = self::tableRows($tables, $table);
            $columns = array_map(static fn (\Doctrine\DBAL\Schema\Column $column): string => $column->getObjectName()->getIdentifier()->getValue(), $schema->introspectTableColumnsByUnquotedName($table));
            sort($columns);
            $existing = array_column($this->rows($table, $scope, $instance), 'id');
            $ids[$table] = [];
            foreach ($rows as $row) {
                $rowColumns = array_keys($row);
                sort($rowColumns);
                if ($rowColumns !== $columns || !isset($row['id']) || filter_var($row['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                    throw new \InvalidArgumentException('Invalid full Musicbot row or schema mismatch in '.$table.'.');
                }
                $id = (string) $row['id'];
                if (isset($ids[$table][$id])) {
                    throw new \InvalidArgumentException('Duplicate full Musicbot row in '.$table.'.');
                }
                $ids[$table][$id] = true;
                foreach (['instance_id', 'musicbot_instance_id'] as $field) {
                    if (array_key_exists($field, $row) && (string) $row[$field] !== (string) $instance->getId()) {
                        throw new \InvalidArgumentException('Full Musicbot row belongs to a different instance.');
                    }
                }
                if (array_key_exists('customer_id', $row) && $row['customer_id'] !== null && (string) $row['customer_id'] !== (string) $instance->getCustomer()->getId()) {
                    throw new \InvalidArgumentException('Full Musicbot row belongs to a different customer.');
                }
                if ($table === 'musicbot_tracks' && $row['file_path'] !== null) {
                    $this->validateMediaPath((string) $row['file_path'], $instance->getInstallPath());
                }
            }
            foreach (array_chunk(array_column($rows, 'id'), 500) as $batch) {
                $occupied = $this->connection->fetchFirstColumn('SELECT id FROM '.$table.' WHERE id IN (?)', [$batch], [ArrayParameterType::INTEGER]);
                foreach (array_keys($occupied) as $index) {
                    if (!in_array($occupied[$index], $existing, false)) {
                        throw new \InvalidArgumentException('Full Musicbot row identifier belongs to another resource in '.$table.'.');
                    }
                }
            }
        }
        foreach (self::SCOPES as $table => $_scope) {
            foreach ($schema->introspectTableForeignKeyConstraintsByUnquotedName($table) as $fk) {
                $target = $fk->getReferencedTableName()->getUnqualifiedName()->getValue();
                $local = array_map(static fn (\Doctrine\DBAL\Schema\Name\UnqualifiedName $column): string => $column->getIdentifier()->getValue(), $fk->getReferencingColumnNames());
                if (count($local) !== 1) {
                    throw new \InvalidArgumentException('Unsupported compound Musicbot foreign key.');
                }
                if (isset(self::SCOPES[$target])) {
                    $currentTargets = $this->rows($target, self::SCOPES[$target], $instance);
                    $currentRows = array_column($this->rows($table, self::SCOPES[$table], $instance), 'id');
                    $column = $this->connection->getDatabasePlatform()->quoteSingleIdentifier($local[0]);
                    foreach (array_chunk(array_column($currentTargets, 'id'), 500) as $batch) {
                        $references = $this->connection->fetchFirstColumn('SELECT id FROM '.$table.' WHERE '.$column.' IN (?)', [$batch], [ArrayParameterType::INTEGER]);
                        foreach (array_keys($references) as $index) {
                            if (!in_array($references[$index], $currentRows, false)) {
                                throw new \InvalidArgumentException('Full Musicbot metadata is shared with another instance; recovery cannot delete its references.');
                            }
                        }
                    }
                }
                foreach (self::tableRows($tables, $table) as $row) {
                    $value = $row[$local[0]];
                    if ($value === null) {
                        continue;
                    }
                    if (isset($ids[$target]) && !isset($ids[$target][(string) $value])) {
                        throw new \InvalidArgumentException('Full Musicbot state references media or metadata outside this backup: '.$table.'.'.$local[0]);
                    }
                    if ($target === 'users') {
                        $userIds[(string) $value] = $value;
                    }
                }
            }
        }
        foreach (array_chunk(array_values($userIds), 500) as $batch) {
            if (count($this->connection->fetchFirstColumn('SELECT id FROM users WHERE id IN (?)', [$batch], [ArrayParameterType::INTEGER])) !== count($batch)) {
                throw new \InvalidArgumentException('Full Musicbot state references a missing user.');
            }
        }
    }

    /** Caller owns the transaction.
     * @param array<string, mixed> $state
     */
    public function apply(MusicbotInstance $instance, array $state): void
    {
        $this->validate($instance, $state);
        $tables = JsonMap::fromValue($state['tables']) ?? [];
        // Remove dependants first. The database keeps all foreign keys enabled.
        foreach (array_reverse(self::SCOPES, true) as $table => $scope) {
            foreach ($this->rows($table, $scope, $instance) as $row) {
                $this->connection->delete($table, ['id' => $row['id']]);
            }
        }
        foreach (self::SCOPES as $table => $_scope) {
            foreach (self::tableRows($tables, $table) as $row) {
                $quoted = [];
                foreach (array_keys($row) as $column) {
                    $value = $row[$column];
                    $quoted[$this->connection->getDatabasePlatform()->quoteSingleIdentifier($column)] = $value;
                }
                $this->connection->insert($table, $quoted);
            }
        }
        $settings = JsonMap::fromValue($state['instance']) ?? [];
        $savedConfig = JsonMap::decode((string) $settings['instance_config']) ?? [];
        $currentConfig = $instance->getInstanceConfig();
        foreach (self::VOLATILE_KEYS as $key) {
            if (array_key_exists($key, $currentConfig)) {
                $savedConfig += [$key => $currentConfig[$key]];
            }
        }
        $settings['instance_config'] = json_encode($savedConfig, JSON_THROW_ON_ERROR);
        $this->connection->update('musicbot_instances', $settings, ['id' => $instance->getId()]);
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $table, string $scope, MusicbotInstance $instance): array
    {
        $parameters = str_contains($scope, ':customer') ? ['customer' => $instance->getCustomer()->getId()] : ['instance' => $instance->getId()];

        return $this->connection->fetchAllAssociative('SELECT * FROM '.$table.' WHERE '.$scope.' ORDER BY id', $parameters);
    }

    /**
     * @param array<string, mixed> $tables
     * @return list<array<string, scalar|null>>
     */
    private static function tableRows(array $tables, string $table): array
    {
        $values = JsonArray::fromValue($tables[$table] ?? null);
        if ($values === null || !array_is_list($values)) {
            throw new \InvalidArgumentException('Invalid full Musicbot table rows.');
        }
        $rows = [];
        foreach (array_keys($values) as $index) {
            $row = JsonMap::fromValue($values[$index]);
            if ($row === null) {
                throw new \InvalidArgumentException('Invalid full Musicbot table row.');
            }
            $scalarRow = [];
            foreach (array_keys($row) as $column) {
                $value = $row[$column];
                if ($value !== null && !is_scalar($value)) {
                    throw new \InvalidArgumentException('Invalid full Musicbot database scalar.');
                }
                $scalarRow[$column] = $value;
            }
            $rows[] = $scalarRow;
        }

        return $rows;
    }

    /** Verify every catalogued local media file against its stored checksum.
     * @param array<string, mixed> $state
     */
    public function validateAssets(MusicbotInstance $instance, array $state, string $archivePath): void
    {
        $archive = new \PharData($archivePath);
        $tables = JsonMap::fromValue($state['tables'] ?? null) ?? [];
        foreach (self::tableRows($tables, 'musicbot_tracks') as $track) {
            if ($track['file_path'] === null) {
                continue;
            }
            $path = (string) $track['file_path'];
            $this->validateMediaPath($path, $instance->getInstallPath());
            $relative = str_starts_with($path, '/') ? substr($path, strlen(rtrim($instance->getInstallPath(), '/')) + 1) : $path;
            $entryName = 'files/primary/'.$relative;
            if (!isset($archive[$entryName])) {
                throw new \InvalidArgumentException('Full Musicbot archive is missing a catalogued media file.');
            }
            $entry = $archive[$entryName];
            if (!$entry->isFile() || $entry->isLink()) {
                throw new \InvalidArgumentException('Full Musicbot media must be a regular file.');
            }
            $expected = $track['sha256'];
            $actual = $this->streams->checksum($entry->getPathname());
            if (!is_string($expected) || !hash_equals($expected, $actual)) {
                throw new \InvalidArgumentException('Full Musicbot catalogued media checksum does not match.');
            }
        }
    }

    private function validateMediaPath(string $path, string $root): void
    {
        $relative = str_starts_with($path, '/') ? substr($path, strlen(rtrim($root, '/')) + 1) : $path;
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\') || str_contains($path, '://')
            || (str_starts_with($path, '/') && !str_starts_with($path, rtrim($root, '/').'/'))
            || in_array('..', explode('/', $relative), true)) {
            throw new \InvalidArgumentException('Musicbot media files must reside inside the managed instance directory.');
        }
    }
}
