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

        // Find all duplicate groups (same game_key, more than one row).
        // The survivor is the row with the highest id (most recently inserted by migrations).
        $groups = $this->connection->fetchAllAssociative(
            'SELECT game_key, MAX(id) AS keep_id FROM game_templates GROUP BY game_key HAVING COUNT(*) > 1'
        );

        foreach ($groups as $group) {
            $keepId  = (int) $group['keep_id'];
            $gameKey = (string) $group['game_key'];

            // Collect all ids that will be deleted (every id < keep_id for this game_key).
            $dropIds = $this->connection->fetchFirstColumn(
                'SELECT id FROM game_templates WHERE game_key = :key AND id != :keep ORDER BY id',
                ['key' => $gameKey, 'keep' => $keepId]
            );

            foreach ($dropIds as $rawId) {
                $dropId = (int) $rawId;

                // Re-point live instances to the surviving template row BEFORE deleting.
                // Without this step the FK on instances.template_id → game_templates.id
                // raises SQLSTATE[23000] and aborts the migration.
                $this->addSql(
                    'UPDATE instances SET template_id = ? WHERE template_id = ?',
                    [$keepId, $dropId]
                );

                // Plugin rows for the duplicate are canonical duplicates of the survivor's
                // plugins; drop them so the FK on game_template_plugins.template_id is clear.
                if ($schema->hasTable('game_template_plugins')) {
                    $this->addSql(
                        'DELETE FROM game_template_plugins WHERE template_id = ?',
                        [$dropId]
                    );
                }

                // Re-point any shop product that referenced the duplicate template.
                if ($schema->hasTable('shop_products')) {
                    $this->addSql(
                        'UPDATE shop_products SET template_id = ? WHERE template_id = ?',
                        [$keepId, $dropId]
                    );
                }
            }
        }

        // All referencing rows have been migrated; now delete the duplicate template rows.
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
