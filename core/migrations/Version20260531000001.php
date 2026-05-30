<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260531000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove duplicate game_template rows and add UNIQUE constraint on game_key.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('game_templates')) {
            return;
        }

        // Remove duplicate rows, keeping the one with the highest id (most recently updated by migrations).
        $this->addSql(
            'DELETE t1 FROM game_templates t1
             INNER JOIN game_templates t2
             ON t1.game_key = t2.game_key AND t1.id < t2.id'
        );

        // Add unique constraint so this can never happen again.
        $this->addSql('ALTER TABLE game_templates ADD UNIQUE KEY uq_game_templates_game_key (game_key)');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('game_templates')) {
            return;
        }

        $this->addSql('ALTER TABLE game_templates DROP INDEX uq_game_templates_game_key');
    }
}
