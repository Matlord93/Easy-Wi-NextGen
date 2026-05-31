<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20260323100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Deduplicate game templates, update CS2 binary path, and store agent job concurrency.';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('game_templates')) {
            $this->addSql(
                'DELETE FROM game_templates WHERE game_key IS NOT NULL AND id NOT IN ('
                . 'SELECT id FROM (SELECT MIN(id) AS id FROM game_templates WHERE game_key IS NOT NULL GROUP BY game_key) dedupe'
                . ')',
            );

            $table = $schema->getTable('game_templates');
            if (!$table->hasIndex('uniq_game_templates_key')) {
                $this->addSql('CREATE UNIQUE INDEX uniq_game_templates_key ON game_templates (game_key)');
            }
        }

        if (!$schema->hasTable('agents')) {
            return;
        }

        $table = $schema->getTable('agents');
        if ($table->hasColumn('job_concurrency')) {
            return;
        }

        $this->addSql('ALTER TABLE agents ADD job_concurrency INT NOT NULL DEFAULT 1');
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('agents')) {
            $table = $schema->getTable('agents');
            if ($table->hasColumn('job_concurrency')) {
                $this->addSql('ALTER TABLE agents DROP job_concurrency');
            }
        }

        if (!$schema->hasTable('game_templates')) {
            return;
        }

        $table = $schema->getTable('game_templates');
        if ($table->hasIndex('uniq_game_templates_key')) {
            $this->addSql('DROP INDEX uniq_game_templates_key ON game_templates');
        }
    }
}
