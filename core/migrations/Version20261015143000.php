<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261015143000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Database self-service hardening: uniqueness by customer+engine and identifier metadata constraints.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('databases')) {
            return;
        }

        $table = $schema->getTable('databases');
        if (!$table->hasIndex('uniq_databases_customer_engine_name')) {
            $this->addSql('CREATE UNIQUE INDEX uniq_databases_customer_engine_name ON `databases` (customer_id, engine, name)');
        }
        if (!$table->hasIndex('uniq_databases_customer_engine_username')) {
            $this->addSql('CREATE UNIQUE INDEX uniq_databases_customer_engine_username ON `databases` (customer_id, engine, username)');
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('databases')) {
            return;
        }

        $table = $schema->getTable('databases');
        if ($table->hasIndex('uniq_databases_customer_engine_username')) {
            $this->addSql('DROP INDEX uniq_databases_customer_engine_username ON `databases`');
        }
        if ($table->hasIndex('uniq_databases_customer_engine_name')) {
            $this->addSql('DROP INDEX uniq_databases_customer_engine_name ON `databases`');
        }
    }
}
