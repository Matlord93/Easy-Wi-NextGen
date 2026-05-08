<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260506150000 extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Expand instance SFTP credential error messages.';
    }

    public function up(Schema $schema): void
    {
        if ($this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            return;
        }

        if (!$schema->hasTable('instance_sftp_credentials')) {
            return;
        }

        $table = $schema->getTable('instance_sftp_credentials');
        if ($table->hasColumn('last_error_message')) {
            $this->addSql('ALTER TABLE instance_sftp_credentials MODIFY last_error_message LONGTEXT DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        if ($this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            return;
        }

        if (!$schema->hasTable('instance_sftp_credentials')) {
            return;
        }

        $table = $schema->getTable('instance_sftp_credentials');
        if ($table->hasColumn('last_error_message')) {
            $this->addSql('ALTER TABLE instance_sftp_credentials MODIFY last_error_message VARCHAR(255) DEFAULT NULL');
        }
    }
}
