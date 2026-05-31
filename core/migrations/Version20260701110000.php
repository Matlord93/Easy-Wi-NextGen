<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20260701110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add webspace SFTP credentials table.';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('webspace_sftp_credentials')) {
            return;
        }

        $this->addSql('CREATE TABLE webspace_sftp_credentials (id INT AUTO_INCREMENT NOT NULL, webspace_id INT NOT NULL, username VARCHAR(190) NOT NULL, encrypted_password JSON NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_webspace_sftp_webspace (webspace_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE webspace_sftp_credentials ADD CONSTRAINT FK_WEBSPACE_SFTP_WEBSPACE FOREIGN KEY (webspace_id) REFERENCES webspaces (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('webspace_sftp_credentials')) {
            return;
        }

        $this->addSql('DROP TABLE webspace_sftp_credentials');
    }
}
