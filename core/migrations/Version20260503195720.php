<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260503195720 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE contact_messages CHANGE ip_address ip_address VARCHAR(45) NOT NULL, CHANGE status status VARCHAR(20) NOT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE replied_at replied_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE contact_messages ADD CONSTRAINT FK_41278201F6BD1646 FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_41278201F6BD1646 ON contact_messages (site_id)');
        $this->addSql('ALTER TABLE game_template_plugins CHANGE install_mode install_mode VARCHAR(32) NOT NULL');
        $this->addSql('ALTER TABLE team_members ADD team_name VARCHAR(140) DEFAULT NULL');
        $this->addSql('DROP INDEX IDX_7AED7913A76ED395 ON user_sessions');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE contact_messages DROP FOREIGN KEY FK_41278201F6BD1646');
        $this->addSql('DROP INDEX IDX_41278201F6BD1646 ON contact_messages');
        $this->addSql('ALTER TABLE contact_messages CHANGE ip_address ip_address VARCHAR(45) DEFAULT \'\' NOT NULL, CHANGE status status VARCHAR(20) DEFAULT \'new\' NOT NULL, CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE replied_at replied_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE game_template_plugins CHANGE install_mode install_mode VARCHAR(32) DEFAULT \'extract\' NOT NULL');
        $this->addSql('ALTER TABLE team_members DROP team_name');
        $this->addSql('CREATE INDEX IDX_7AED7913A76ED395 ON user_sessions (user_id)');
    }
}
