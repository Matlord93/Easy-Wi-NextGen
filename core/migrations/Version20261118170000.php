<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261118170000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Allow a mail server to select its actual provisioning agent independently of webspace.';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $table = $schema->getTable('mail_nodes');
        if (!$table->hasColumn('agent_id')) {
            $table->addColumn('agent_id', 'string', ['length' => 64, 'notnull' => false]);
            $table->addIndex(['agent_id'], 'idx_mail_node_agent');
            $table->addForeignKeyConstraint('agents', ['agent_id'], ['id'], ['onDelete' => 'SET NULL'], 'fk_mail_node_agent');
        }
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $table = $schema->getTable('mail_nodes');
        if ($table->hasForeignKey('fk_mail_node_agent')) {
            $table->removeForeignKey('fk_mail_node_agent');
        }
        if ($table->hasIndex('idx_mail_node_agent')) {
            $table->dropIndex('idx_mail_node_agent');
        }
        if ($table->hasColumn('agent_id')) {
            $table->dropColumn('agent_id');
        }
    }
}
