<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261116000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Merge gameserver production hardening migrations (SFTP rotation, backups metadata/targets/schedules, instance schedule run tracking).';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('instance_sftp_credentials')) {
            $table = $schema->getTable('instance_sftp_credentials');
            if (!$table->hasColumn('rotated_at')) {
                $this->addSql("ALTER TABLE instance_sftp_credentials ADD rotated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
            }
            if (!$table->hasColumn('expires_at')) {
                $this->addSql("ALTER TABLE instance_sftp_credentials ADD expires_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
            }
            $this->addSql('UPDATE instance_sftp_credentials SET rotated_at = COALESCE(updated_at, created_at) WHERE rotated_at IS NULL');
        }

        if ($schema->hasTable('backups')) {
            $table = $schema->getTable('backups');
            if (!$table->hasColumn('size_bytes')) {
                $this->addSql('ALTER TABLE backups ADD size_bytes BIGINT DEFAULT NULL');
            }
            if (!$table->hasColumn('checksum_sha256')) {
                $this->addSql('ALTER TABLE backups ADD checksum_sha256 VARCHAR(128) DEFAULT NULL');
            }
            if (!$table->hasColumn('archive_path')) {
                $this->addSql('ALTER TABLE backups ADD archive_path VARCHAR(1024) DEFAULT NULL');
            }
            if (!$table->hasColumn('error_code')) {
                $this->addSql('ALTER TABLE backups ADD error_code VARCHAR(120) DEFAULT NULL');
            }
            if (!$table->hasColumn('error_message')) {
                $this->addSql('ALTER TABLE backups ADD error_message LONGTEXT DEFAULT NULL');
            }
        }

        if ($schema->hasTable('backup_targets')) {
            $table = $schema->getTable('backup_targets');
            if (!$table->hasColumn('enabled')) {
                $this->addSql('ALTER TABLE backup_targets ADD enabled TINYINT(1) NOT NULL DEFAULT 1');
            }
        }

        if ($schema->hasTable('backup_schedules')) {
            $table = $schema->getTable('backup_schedules');
            if (!$table->hasColumn('time_zone')) {
                $this->addSql("ALTER TABLE backup_schedules ADD time_zone VARCHAR(100) NOT NULL DEFAULT 'UTC'");
            }
            if (!$table->hasColumn('compression')) {
                $this->addSql("ALTER TABLE backup_schedules ADD compression VARCHAR(32) NOT NULL DEFAULT 'gzip'");
            }
            if (!$table->hasColumn('stop_before')) {
                $this->addSql('ALTER TABLE backup_schedules ADD stop_before TINYINT(1) NOT NULL DEFAULT 0');
            }
            if (!$table->hasColumn('backup_target_id')) {
                $this->addSql('ALTER TABLE backup_schedules ADD backup_target_id INT DEFAULT NULL');
            }
            if (!$table->hasColumn('last_run_at')) {
                $this->addSql("ALTER TABLE backup_schedules ADD last_run_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
            }
            if (!$table->hasColumn('last_status')) {
                $this->addSql('ALTER TABLE backup_schedules ADD last_status VARCHAR(32) DEFAULT NULL');
            }
            if (!$table->hasColumn('last_error_code')) {
                $this->addSql('ALTER TABLE backup_schedules ADD last_error_code VARCHAR(64) DEFAULT NULL');
            }

            if (!$table->hasIndex('IDX_79FF3B0A62BAA4E5')) {
                $this->addSql('CREATE INDEX IDX_79FF3B0A62BAA4E5 ON backup_schedules (backup_target_id)');
            }

            $hasForeignKey = false;
            foreach ($table->getForeignKeys() as $foreignKey) {
                if ($foreignKey->getName() === 'FK_79FF3B0A62BAA4E5') {
                    $hasForeignKey = true;
                    break;
                }
            }
            if (!$hasForeignKey) {
                $this->addSql('ALTER TABLE backup_schedules ADD CONSTRAINT FK_79FF3B0A62BAA4E5 FOREIGN KEY (backup_target_id) REFERENCES backup_targets (id) ON DELETE SET NULL');
            }
        }

        if ($schema->hasTable('instance_schedules')) {
            $table = $schema->getTable('instance_schedules');
            if (!$table->hasColumn('last_run_at')) {
                $this->addSql("ALTER TABLE instance_schedules ADD last_run_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
            }
            if (!$table->hasColumn('last_status')) {
                $this->addSql('ALTER TABLE instance_schedules ADD last_status VARCHAR(32) DEFAULT NULL');
            }
            if (!$table->hasColumn('last_error_code')) {
                $this->addSql('ALTER TABLE instance_schedules ADD last_error_code VARCHAR(64) DEFAULT NULL');
            }
        }
    }

    public function down(Schema $schema): void
    {
        if ($this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $this->abortIf(true, 'Irreversible on SQLite.');
        }

        if ($schema->hasTable('instance_schedules')) {
            $table = $schema->getTable('instance_schedules');
            if ($table->hasColumn('last_error_code')) {
                $this->addSql('ALTER TABLE instance_schedules DROP last_error_code');
            }
            if ($table->hasColumn('last_status')) {
                $this->addSql('ALTER TABLE instance_schedules DROP last_status');
            }
            if ($table->hasColumn('last_run_at')) {
                $this->addSql('ALTER TABLE instance_schedules DROP last_run_at');
            }
        }

        if ($schema->hasTable('backup_schedules')) {
            $table = $schema->getTable('backup_schedules');
            foreach ($table->getForeignKeys() as $foreignKey) {
                if ($foreignKey->getName() === 'FK_79FF3B0A62BAA4E5') {
                    $this->addSql('ALTER TABLE backup_schedules DROP FOREIGN KEY FK_79FF3B0A62BAA4E5');
                    break;
                }
            }
            if ($table->hasIndex('IDX_79FF3B0A62BAA4E5')) {
                $this->addSql('DROP INDEX IDX_79FF3B0A62BAA4E5 ON backup_schedules');
            }
            if ($table->hasColumn('last_error_code')) {
                $this->addSql('ALTER TABLE backup_schedules DROP last_error_code');
            }
            if ($table->hasColumn('last_status')) {
                $this->addSql('ALTER TABLE backup_schedules DROP last_status');
            }
            if ($table->hasColumn('last_run_at')) {
                $this->addSql('ALTER TABLE backup_schedules DROP last_run_at');
            }
            if ($table->hasColumn('backup_target_id')) {
                $this->addSql('ALTER TABLE backup_schedules DROP backup_target_id');
            }
            if ($table->hasColumn('stop_before')) {
                $this->addSql('ALTER TABLE backup_schedules DROP stop_before');
            }
            if ($table->hasColumn('compression')) {
                $this->addSql('ALTER TABLE backup_schedules DROP compression');
            }
            if ($table->hasColumn('time_zone')) {
                $this->addSql('ALTER TABLE backup_schedules DROP time_zone');
            }
        }

        if ($schema->hasTable('backup_targets')) {
            $table = $schema->getTable('backup_targets');
            if ($table->hasColumn('enabled')) {
                $this->addSql('ALTER TABLE backup_targets DROP enabled');
            }
        }

        if ($schema->hasTable('backups')) {
            $table = $schema->getTable('backups');
            if ($table->hasColumn('error_message')) {
                $this->addSql('ALTER TABLE backups DROP error_message');
            }
            if ($table->hasColumn('error_code')) {
                $this->addSql('ALTER TABLE backups DROP error_code');
            }
            if ($table->hasColumn('archive_path')) {
                $this->addSql('ALTER TABLE backups DROP archive_path');
            }
            if ($table->hasColumn('checksum_sha256')) {
                $this->addSql('ALTER TABLE backups DROP checksum_sha256');
            }
            if ($table->hasColumn('size_bytes')) {
                $this->addSql('ALTER TABLE backups DROP size_bytes');
            }
        }

        if ($schema->hasTable('instance_sftp_credentials')) {
            $table = $schema->getTable('instance_sftp_credentials');
            if ($table->hasColumn('expires_at')) {
                $this->addSql('ALTER TABLE instance_sftp_credentials DROP expires_at');
            }
            if ($table->hasColumn('rotated_at')) {
                $this->addSql('ALTER TABLE instance_sftp_credentials DROP rotated_at');
            }
        }
    }
}
