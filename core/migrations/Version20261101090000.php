<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20261101090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add instance access metadata and one-time reveal tracking to instance_sftp_credentials.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('instance_sftp_credentials')) {
            return;
        }

        $table = $schema->getTable('instance_sftp_credentials');
        if (!$table->hasColumn('backend')) {
            $this->addSql("ALTER TABLE instance_sftp_credentials ADD backend VARCHAR(32) NOT NULL DEFAULT 'NONE'");
        }
        if (!$table->hasColumn('host')) {
            $this->addSql('ALTER TABLE instance_sftp_credentials ADD host VARCHAR(190) DEFAULT NULL');
        }
        if (!$table->hasColumn('port')) {
            $this->addSql('ALTER TABLE instance_sftp_credentials ADD port INT DEFAULT NULL');
        }
        if (!$table->hasColumn('root_path')) {
            $this->addSql('ALTER TABLE instance_sftp_credentials ADD root_path VARCHAR(255) DEFAULT NULL');
        }
        if (!$table->hasColumn('revealed_at')) {
            $this->addSql("ALTER TABLE instance_sftp_credentials ADD revealed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
        }
        if (!$table->hasColumn('last_error_code')) {
            $this->addSql('ALTER TABLE instance_sftp_credentials ADD last_error_code VARCHAR(64) DEFAULT NULL');
        }
        if (!$table->hasColumn('last_error_message')) {
            $this->addSql('ALTER TABLE instance_sftp_credentials ADD last_error_message LONGTEXT DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('instance_sftp_credentials')) {
            return;
        }

        $table = $schema->getTable('instance_sftp_credentials');
        if ($table->hasColumn('last_error_message')) {
            $this->addSql('ALTER TABLE instance_sftp_credentials DROP last_error_message');
        }
        if ($table->hasColumn('last_error_code')) {
            $this->addSql('ALTER TABLE instance_sftp_credentials DROP last_error_code');
        }
        if ($table->hasColumn('revealed_at')) {
            $this->addSql('ALTER TABLE instance_sftp_credentials DROP revealed_at');
        }
        if ($table->hasColumn('root_path')) {
            $this->addSql('ALTER TABLE instance_sftp_credentials DROP root_path');
        }
        if ($table->hasColumn('port')) {
            $this->addSql('ALTER TABLE instance_sftp_credentials DROP port');
        }
        if ($table->hasColumn('host')) {
            $this->addSql('ALTER TABLE instance_sftp_credentials DROP host');
        }
        if ($table->hasColumn('backend')) {
            $this->addSql('ALTER TABLE instance_sftp_credentials DROP backend');
        }
    }
}
