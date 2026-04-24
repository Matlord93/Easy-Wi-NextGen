<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260424113000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add missing indexes for user_sessions.user_id and user_sessions.expires_at.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('user_sessions')) {
            return;
        }

        $table = $schema->getTable('user_sessions');

        if (!$table->hasIndex('idx_user_sessions_user_id') && $table->hasColumn('user_id')) {
            $this->addSql('CREATE INDEX idx_user_sessions_user_id ON user_sessions (user_id)');
        }

        if (!$table->hasIndex('idx_user_sessions_expires_at') && $table->hasColumn('expires_at')) {
            $this->addSql('CREATE INDEX idx_user_sessions_expires_at ON user_sessions (expires_at)');
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('user_sessions')) {
            return;
        }

        $table = $schema->getTable('user_sessions');

        if ($table->hasIndex('idx_user_sessions_user_id')) {
            $this->addSql('DROP INDEX idx_user_sessions_user_id ON user_sessions');
        }

        if ($table->hasIndex('idx_user_sessions_expires_at')) {
            $this->addSql('DROP INDEX idx_user_sessions_expires_at ON user_sessions');
        }
    }
}
