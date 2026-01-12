<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250318120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add instance SFTP credentials table.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('instance_sftp_credentials')) {
            $this->addSql('CREATE TABLE instance_sftp_credentials (id INT AUTO_INCREMENT NOT NULL, instance_id INT NOT NULL, username VARCHAR(190) NOT NULL, encrypted_password JSON NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_instance_sftp_credentials_instance (instance_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE instance_sftp_credentials ADD CONSTRAINT fk_instance_sftp_credentials_instance FOREIGN KEY (instance_id) REFERENCES instances (id) ON DELETE CASCADE');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('instance_sftp_credentials')) {
            $this->addSql('ALTER TABLE instance_sftp_credentials DROP FOREIGN KEY fk_instance_sftp_credentials_instance');
            $this->addSql('DROP TABLE instance_sftp_credentials');
        }
    }
}
