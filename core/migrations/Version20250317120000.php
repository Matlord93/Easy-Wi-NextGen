<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250317120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add rules column to firewall_states.';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('firewall_states')) {
            $this->addSql('ALTER TABLE firewall_states ADD rules JSON DEFAULT NULL');
            $this->addSql("UPDATE firewall_states SET rules = '[]' WHERE rules IS NULL");
            $this->addSql('ALTER TABLE firewall_states MODIFY rules JSON NOT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('firewall_states')) {
            $this->addSql('ALTER TABLE firewall_states DROP rules');
        }
    }
}
