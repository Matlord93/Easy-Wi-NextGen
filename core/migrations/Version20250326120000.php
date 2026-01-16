<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250326120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add template requirements and instance setup values.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE game_templates ADD requirement_vars JSON DEFAULT NULL, ADD requirement_secrets JSON DEFAULT NULL");
        $this->addSql("UPDATE game_templates SET requirement_vars = '[]' WHERE requirement_vars IS NULL");
        $this->addSql("UPDATE game_templates SET requirement_secrets = '[]' WHERE requirement_secrets IS NULL");
        $this->addSql("ALTER TABLE game_templates MODIFY requirement_vars JSON NOT NULL");
        $this->addSql("ALTER TABLE game_templates MODIFY requirement_secrets JSON NOT NULL");

        $this->addSql("ALTER TABLE instances ADD setup_vars JSON DEFAULT NULL, ADD setup_secrets JSON DEFAULT NULL");
        $this->addSql("UPDATE instances SET setup_vars = '[]' WHERE setup_vars IS NULL");
        $this->addSql("UPDATE instances SET setup_secrets = '[]' WHERE setup_secrets IS NULL");
        $this->addSql("ALTER TABLE instances MODIFY setup_vars JSON NOT NULL");
        $this->addSql("ALTER TABLE instances MODIFY setup_secrets JSON NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE instances DROP setup_vars, DROP setup_secrets');
        $this->addSql('ALTER TABLE game_templates DROP requirement_vars, DROP requirement_secrets');
    }
}
