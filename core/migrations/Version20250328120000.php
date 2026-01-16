<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250328120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add server SFTP access table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE server_sftp_access (id INT AUTO_INCREMENT NOT NULL, server_id INT NOT NULL, username VARCHAR(64) NOT NULL, enabled TINYINT(1) NOT NULL, password_set_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', `keys` JSON NOT NULL, UNIQUE INDEX uniq_server_sftp_access_server (server_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE server_sftp_access ADD CONSTRAINT FK_SERVER_SFTP_ACCESS_SERVER FOREIGN KEY (server_id) REFERENCES instances (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE server_sftp_access DROP FOREIGN KEY FK_SERVER_SFTP_ACCESS_SERVER');
        $this->addSql('DROP TABLE server_sftp_access');
    }
}
