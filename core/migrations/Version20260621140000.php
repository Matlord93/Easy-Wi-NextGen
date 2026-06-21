<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260621140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Finalize musicbot schema synchronization with Doctrine metadata.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_musicbot_customer_limits_customer ON musicbot_customer_limits');
        $this->addSql('ALTER TABLE musicbot_stream_settings CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_musicbot_customer_limits_customer ON musicbot_customer_limits (customer_id)');
    }
}
