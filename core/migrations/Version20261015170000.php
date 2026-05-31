<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20261015170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Backfill and keep mail_users projection in sync with existing mailboxes.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('mail_users') || !$schema->hasTable('mailboxes')) {
            return;
        }

        $this->addSql("INSERT INTO mail_users (mailbox_id, customer_id, domain_id, local_part, address, password_hash, quota_mb, enabled, created_at, updated_at)
            SELECT m.id, m.customer_id, m.domain_id, m.local_part, m.address, m.password_hash, m.quota, m.enabled, m.created_at, m.updated_at
            FROM mailboxes m
            ON CONFLICT (mailbox_id) DO UPDATE SET
                customer_id = EXCLUDED.customer_id,
                domain_id = EXCLUDED.domain_id,
                local_part = EXCLUDED.local_part,
                address = EXCLUDED.address,
                password_hash = EXCLUDED.password_hash,
                quota_mb = EXCLUDED.quota_mb,
                enabled = EXCLUDED.enabled,
                updated_at = EXCLUDED.updated_at");
    }

    public function down(Schema $schema): void
    {
        // Data sync migration is intentionally irreversible.
    }
}
