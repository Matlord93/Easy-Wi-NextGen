<?php

declare(strict_types=1);

namespace App\Tests\Module\Core\Application\Backup;

use App\Module\Core\Application\Backup\Resource\MailBackupMetadata;
use App\Module\Core\Application\Backup\Resource\ManagedBackupResource;
use App\Module\Core\Domain\Entity\Agent;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class MailBackupMetadataTest extends TestCase
{
    private Connection $connection;
    private MailBackupMetadata $metadata;
    private ManagedBackupResource $resource;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('PRAGMA foreign_keys = ON');
        foreach (['mail_domains' => 'owner_id', 'mailboxes' => 'customer_id', 'mail_users' => 'customer_id', 'mail_aliases' => 'customer_id', 'mail_forwardings' => 'customer_id', 'mail_dkim_keys' => 'customer_id', 'mail_policies' => 'owner_id'] as $table => $owner) {
            $this->connection->executeStatement('CREATE TABLE '.$table.' (id INTEGER PRIMARY KEY, '.$owner.' INT NOT NULL, domain_id INT NOT NULL, setting TEXT, node_id INT, mailbox_id INT)');
            $this->connection->insert($table, ['id' => 10, $owner => 1, 'domain_id' => 7, 'setting' => 'original-'.$table, 'node_id' => 5, 'mailbox_id' => 10]);
            $this->connection->insert($table, ['id' => 20, $owner => 2, 'domain_id' => 8, 'setting' => 'other-tenant', 'node_id' => 6, 'mailbox_id' => 20]);
        }
        $this->connection->executeStatement('CREATE TABLE mail_rate_limits (id INTEGER PRIMARY KEY, customer_id INT NOT NULL, mailbox_id INT NOT NULL REFERENCES mailboxes(id) ON DELETE CASCADE, setting TEXT)');
        $this->connection->insert('mail_rate_limits', ['id' => 10, 'customer_id' => 1, 'mailbox_id' => 10, 'setting' => 'original-limit']);
        $this->connection->insert('mail_rate_limits', ['id' => 20, 'customer_id' => 2, 'mailbox_id' => 20, 'setting' => 'other-limit']);
        $this->resource = new ManagedBackupResource(new Agent('mail-node', ['key_id' => 'test', 'nonce' => '', 'ciphertext' => '']), '1', ['module' => 'mail', 'resource_id' => '10'], 10, 7);
        $this->metadata = new MailBackupMetadata($this->connection);
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    public function testRestoreReinstatesDeletedMailboxAndSettingsAndPreservesOtherTenants(): void
    {
        $snapshot = $this->metadata->snapshot($this->resource);
        $this->connection->delete('mailboxes', ['id' => 10]);
        $this->connection->update('mail_aliases', ['setting' => 'changed'], ['id' => 10]);
        $this->connection->insert('mail_aliases', ['id' => 11, 'customer_id' => 1, 'domain_id' => 7, 'setting' => 'new alias']);
        $this->connection->transactional(fn () => $this->metadata->restore($this->resource, $snapshot));
        self::assertSame('original-mailboxes', $this->connection->fetchOne('SELECT setting FROM mailboxes WHERE id = 10'));
        self::assertSame('original-mail_aliases', $this->connection->fetchOne('SELECT setting FROM mail_aliases WHERE id = 10'));
        self::assertFalse($this->connection->fetchOne('SELECT id FROM mail_aliases WHERE id = 11'));
        self::assertSame('original-limit', $this->connection->fetchOne('SELECT setting FROM mail_rate_limits WHERE id = 10'));
        self::assertSame('other-tenant', $this->connection->fetchOne('SELECT setting FROM mail_aliases WHERE id = 20'));
    }

    public function testCrossTenantRecordIsRejectedBeforeAnyMutation(): void
    {
        $snapshot = $this->metadata->snapshot($this->resource);
        $snapshot['tables']['mailboxes'][0]['id'] = 20;
        $this->expectException(\InvalidArgumentException::class);
        $this->metadata->validate($this->resource, $snapshot);
    }

    public function testRateLimitsCannotReferenceMailboxFromAnotherDomain(): void
    {
        $snapshot = $this->metadata->snapshot($this->resource);
        $snapshot['tables']['mail_rate_limits'][0]['mailbox_id'] = 20;
        $this->expectException(\InvalidArgumentException::class);
        $this->metadata->validate($this->resource, $snapshot);
    }

    public function testTransactionCanRollbackMetadataWhenAgentRestoreFails(): void
    {
        $snapshot = $this->metadata->snapshot($this->resource);
        $this->connection->update('mail_aliases', ['setting' => 'present-value'], ['id' => 10]);
        $this->connection->beginTransaction();
        $this->metadata->restore($this->resource, $snapshot);
        $this->connection->rollBack();
        self::assertSame('present-value', $this->connection->fetchOne('SELECT setting FROM mail_aliases WHERE id = 10'));
    }
}
