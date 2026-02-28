<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261015100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add credentials versioning for global session invalidation.';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('users')) {
            $users = $schema->getTable('users');
            if (!$users->hasColumn('credentials_version')) {
                $this->addSql('ALTER TABLE users ADD credentials_version INT NOT NULL DEFAULT 1');
            }
        }

        if ($schema->hasTable('user_sessions')) {
            $sessions = $schema->getTable('user_sessions');
            if (!$sessions->hasColumn('credentials_version')) {
                $this->addSql('ALTER TABLE user_sessions ADD credentials_version INT NOT NULL DEFAULT 1');
            }
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('user_sessions')) {
            $sessions = $schema->getTable('user_sessions');
            if ($sessions->hasColumn('credentials_version')) {
                $this->addSql('ALTER TABLE user_sessions DROP credentials_version');
            }
        }

        if ($schema->hasTable('users')) {
            $users = $schema->getTable('users');
            if ($users->hasColumn('credentials_version')) {
                $this->addSql('ALTER TABLE users DROP credentials_version');
            }
        }
    }
}
