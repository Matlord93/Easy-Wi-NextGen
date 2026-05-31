<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260531000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fix agent_jobs FK: rename to Doctrine convention and add ON DELETE CASCADE; rename index.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('agent_jobs')) {
            return;
        }

        $table = $schema->getTable('agent_jobs');

        // Drop whatever FK currently exists on node_id (name may vary by environment)
        foreach ($table->getForeignKeys() as $fk) {
            if ($fk->getForeignTableName() === 'agents' && in_array('node_id', $fk->getLocalColumns(), true)) {
                $this->addSql(sprintf('ALTER TABLE agent_jobs DROP FOREIGN KEY `%s`', $fk->getName()));
                break;
            }
        }

        $this->addSql('ALTER TABLE agent_jobs ADD CONSTRAINT FK_508D726460D9FD7 FOREIGN KEY (node_id) REFERENCES agents (id) ON DELETE CASCADE');

        // Rename the single-column index if it exists under the old name
        $indexNames = array_keys($table->getIndexes());
        if (in_array('idx_2789aa3c5c1662b', array_map('strtolower', $indexNames), true)) {
            $this->addSql('ALTER TABLE agent_jobs RENAME INDEX idx_2789aa3c5c1662b TO IDX_508D726460D9FD7');
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('agent_jobs')) {
            return;
        }

        $this->addSql('ALTER TABLE agent_jobs DROP FOREIGN KEY FK_508D726460D9FD7');
        $this->addSql('ALTER TABLE agent_jobs ADD CONSTRAINT agent_jobs_ibfk_1 FOREIGN KEY (node_id) REFERENCES agents (id)');
        $this->addSql('ALTER TABLE agent_jobs RENAME INDEX IDX_508D726460D9FD7 TO idx_2789aa3c5c1662b');
    }
}
