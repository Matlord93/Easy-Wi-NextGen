<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261015130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Harden mail platform: mailbox/alias constraints, DKIM encrypted payload metadata, and remove legacy mail_forwards flow.';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('mailboxes')) {
            $this->addSql('ALTER TABLE mailboxes ADD CONSTRAINT uniq_mailboxes_address UNIQUE (address)');
            $this->addSql('ALTER TABLE mailboxes ADD CONSTRAINT chk_mailboxes_quota_non_negative CHECK (quota >= 0)');
        }

        if ($schema->hasTable('mail_aliases')) {
            $this->addSql('ALTER TABLE mail_aliases ADD CONSTRAINT uniq_mail_aliases_address UNIQUE (address)');
        }

        if ($schema->hasTable('mail_domains')) {
            $table = $schema->getTable('mail_domains');
            if (!$table->hasColumn('dkim_private_key_payload')) {
                $this->addSql('ALTER TABLE mail_domains ADD dkim_private_key_payload JSON DEFAULT NULL');
            }
            if (!$table->hasColumn('dkim_previous_private_key_payload')) {
                $this->addSql('ALTER TABLE mail_domains ADD dkim_previous_private_key_payload JSON DEFAULT NULL');
            }
            if (!$table->hasColumn('dkim_rotated_at')) {
                $this->addSql('ALTER TABLE mail_domains ADD dkim_rotated_at DATETIME DEFAULT NULL');
            }
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('mail_domains')) {
            $table = $schema->getTable('mail_domains');
            if ($table->hasColumn('dkim_rotated_at')) {
                $this->addSql('ALTER TABLE mail_domains DROP dkim_rotated_at');
            }
            if ($table->hasColumn('dkim_previous_private_key_payload')) {
                $this->addSql('ALTER TABLE mail_domains DROP dkim_previous_private_key_payload');
            }
            if ($table->hasColumn('dkim_private_key_payload')) {
                $this->addSql('ALTER TABLE mail_domains DROP dkim_private_key_payload');
            }
        }

        if ($schema->hasTable('mail_aliases')) {
            $this->addSql('ALTER TABLE mail_aliases DROP INDEX uniq_mail_aliases_address');
        }

        if ($schema->hasTable('mailboxes')) {
            $this->addSql('ALTER TABLE mailboxes DROP INDEX uniq_mailboxes_address');
            $this->addSql('ALTER TABLE mailboxes DROP CHECK chk_mailboxes_quota_non_negative');
        }
    }
}
