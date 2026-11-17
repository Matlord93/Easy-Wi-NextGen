<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup\Resource;

use Doctrine\DBAL\Connection;

/** Tenant-scoped control-plane data accompanies the Maildir, routing files and DKIM keys. */
final class MailBackupMetadata
{
    private const array TABLE_OWNERS = [
        'mail_domains' => 'owner_id', 'mailboxes' => 'customer_id', 'mail_users' => 'customer_id',
        'mail_aliases' => 'customer_id', 'mail_forwardings' => 'customer_id', 'mail_dkim_keys' => 'customer_id',
        'mail_policies' => 'owner_id', 'mail_rate_limits' => 'customer_id',
    ];

    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return array<string, mixed> */
    public function snapshot(ManagedBackupResource $resource): array
    {
        return $this->connection->transactional(function () use ($resource): array {
            $rows = [];
            $bytes = 0;
            foreach (self::TABLE_OWNERS as $table => $ownerColumn) {
                $rows[$table] = [];
                foreach ($this->connection->iterateAssociative($this->select($table).' AND '.$ownerColumn.' = ? ORDER BY id', [$resource->domainId, $resource->customerId]) as $row) {
                    $bytes += strlen(json_encode($row, JSON_THROW_ON_ERROR));
                    if ($bytes > 8 * 1024 * 1024) {
                        throw new \RuntimeException('Mail control-plane metadata exceeds the bounded backup manifest size.');
                    }
                    $rows[$table][] = $row;
                }
            }
            return ['version' => 1, 'mail_domain_id' => $resource->mailDomainId, 'domain_id' => $resource->domainId, 'tables' => $rows];
        });
    }

    /** Validate the entire record before the first DB or remote mutation. @param array<string, mixed> $metadata */
    public function validate(ManagedBackupResource $resource, array $metadata): void
    {
        $tables = $metadata['tables'] ?? null;
        if (($metadata['version'] ?? null) !== 1 || ($metadata['mail_domain_id'] ?? null) !== $resource->mailDomainId
            || ($metadata['domain_id'] ?? null) !== $resource->domainId || !is_array($tables)
            || array_keys($tables) !== array_keys(self::TABLE_OWNERS)) {
            throw new \InvalidArgumentException('Mail backup metadata does not match the configured domain.');
        }
        $mailboxes = [];
        $schema = $this->connection->createSchemaManager();
        foreach (self::TABLE_OWNERS as $table => $ownerColumn) {
            $rows = $tables[$table];
            if (!is_array($rows) || !array_is_list($rows)) {
                throw new \InvalidArgumentException('Invalid mail backup table data.');
            }
            $columns = array_map(static fn (\Doctrine\DBAL\Schema\Column $column): string => $column->getObjectName()->getIdentifier()->getValue(), $schema->introspectTableColumnsByUnquotedName($table));
            $seen = [];
            /** @var mixed $row */
            foreach ($rows as $row) {
                if (!is_array($row) || array_diff(array_keys($row), $columns) !== []
                    || !isset($row['id'], $row[$ownerColumn]) || (string) $row[$ownerColumn] !== $resource->customerId
                    || !is_numeric($row['id']) || (int) $row['id'] < 1 || isset($seen[(string) $row['id']])) {
                    throw new \InvalidArgumentException('Invalid or cross-tenant mail backup row.');
                }
                $seen[(string) $row['id']] = true;
                /** @var mixed $value */
                foreach ($row as $value) {
                    if (!is_scalar($value) && $value !== null) {
                        throw new \InvalidArgumentException('Mail backup rows must contain scalar database values.');
                    }
                }
                if ($table === 'mail_rate_limits') {
                    if (!isset($row['mailbox_id']) || !isset($mailboxes[(string) $row['mailbox_id']])) {
                        throw new \InvalidArgumentException('Mail rate limit references another domain.');
                    }
                } elseif ((string) ($row['domain_id'] ?? '') !== (string) $resource->domainId) {
                    throw new \InvalidArgumentException('Mail backup row references another domain.');
                }
                if ($table === 'mailboxes') {
                    $mailboxes[(string) $row['id']] = true;
                }
                if ($table === 'mail_users' && !isset($mailboxes[(string) ($row['mailbox_id'] ?? '')])) {
                    throw new \InvalidArgumentException('Mail user references another domain mailbox.');
                }
                $current = $this->connection->fetchAssociative('SELECT * FROM '.$table.' WHERE id = ?', [$row['id']]);
                if ($current !== false && ((string) $current[$ownerColumn] !== $resource->customerId
                    || ($table !== 'mail_rate_limits' && (string) $current['domain_id'] !== (string) $resource->domainId)
                    || ($table === 'mail_rate_limits' && (string) $current['mailbox_id'] !== (string) ($row['mailbox_id'] ?? '')))) {
                    throw new \InvalidArgumentException('Mail record identity is already used by another resource.');
                }
                if ($table === 'mail_domains' && ((int) $row['id'] !== $resource->mailDomainId || $current === false
                    || (string) ($row['node_id'] ?? '') !== (string) $current['node_id'])) {
                    throw new \InvalidArgumentException('Mail domain identity or node cannot be reassigned by a restore.');
                }
            }
            if ($table === 'mail_domains' && count($rows) !== 1) {
                throw new \InvalidArgumentException('Mail backup requires exactly one domain control-plane record.');
            }
        }
    }

    /** Caller holds a transaction while the Agent restores/applies the matching on-disk configuration. @param array<string, mixed> $metadata */
    public function restore(ManagedBackupResource $resource, array $metadata): void
    {
        $this->validate($resource, $metadata);
        /** @var array<string, list<array<string, scalar|null>>> $tables */
        $tables = $metadata['tables'];
        // Remove additions since the snapshot, children before mailboxes. Keep stable record IDs.
        foreach (array_reverse(array_keys(self::TABLE_OWNERS)) as $table) {
            $ids = array_fill_keys(array_map(static fn (mixed $id): string => (string) $id, array_column($tables[$table], 'id')), true);
            foreach ($this->connection->fetchAllAssociative($this->select($table), [$resource->domainId]) as $row) {
                if (!isset($ids[(string) $row['id']])) {
                    $this->connection->delete($table, ['id' => $row['id'], self::TABLE_OWNERS[$table] => $resource->customerId]);
                }
            }
        }
        foreach ($tables as $table => $rows) {
            foreach ($rows as $row) {
                $id = $row['id'];
                unset($row['id']);
                if ($this->connection->fetchOne('SELECT id FROM '.$table.' WHERE id = ?', [$id]) === false) {
                    $this->connection->insert($table, ['id' => $id] + $row);
                } else {
                    $this->connection->update($table, $row, ['id' => $id]);
                }
            }
        }
    }

    public function connection(): Connection
    {
        return $this->connection;
    }

    private function select(string $table): string
    {
        return 'SELECT * FROM '.$table.' WHERE '.($table === 'mail_rate_limits'
            ? 'mailbox_id IN (SELECT id FROM mailboxes WHERE domain_id = ?)' : 'domain_id = ?');
    }
}
