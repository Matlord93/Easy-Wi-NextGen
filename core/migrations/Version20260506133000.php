<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260506133000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add provisioning state columns to instance_sftp_credentials.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('instance_sftp_credentials')) {
            return;
        }

        $table = $schema->getTable('instance_sftp_credentials');
        if (!$table->hasColumn('status')) {
            $this->addSql("ALTER TABLE instance_sftp_credentials ADD status VARCHAR(32) NOT NULL DEFAULT 'pending'");
        }
        if (!$table->hasColumn('provisioned')) {
            $this->addSql('ALTER TABLE instance_sftp_credentials ADD provisioned TINYINT(1) NOT NULL DEFAULT 0');
        }
        $this->addSql("UPDATE instance_sftp_credentials SET provisioned = CASE WHEN backend <> 'NONE' AND last_error_code IS NULL THEN 1 ELSE 0 END, status = CASE WHEN backend <> 'NONE' AND last_error_code IS NULL THEN 'provisioned' WHEN last_error_code IS NOT NULL THEN 'failed' ELSE 'pending' END");
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('instance_sftp_credentials')) {
            return;
        }

        $table = $schema->getTable('instance_sftp_credentials');
        if ($table->hasColumn('provisioned')) {
            $this->addSql('ALTER TABLE instance_sftp_credentials DROP provisioned');
        }
        if ($table->hasColumn('status')) {
            $this->addSql('ALTER TABLE instance_sftp_credentials DROP status');
        }
    }
}
