<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250219100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add reseller ownership to users.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD reseller_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD CONSTRAINT FK_1483A5E6F8E8F5 FOREIGN KEY (reseller_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_users_reseller ON users (reseller_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP FOREIGN KEY FK_1483A5E6F8E8F5');
        $this->addSql('DROP INDEX idx_users_reseller ON users');
        $this->addSql('ALTER TABLE users DROP reseller_id');
    }
}
