<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20260910120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Squashed CMS/blog/forum/abuse-log schema migration.';
    }

    public function up(Schema $schema): void
    {
        $isSqlite = $this->isSqlitePlatform();

        $this->applyGameserverHardening($schema);
        $this->removeTeamSpeakTemplatesAndDeduplicate($schema);
        $this->applyDatabaseSelfServiceHardening($schema);
        $this->applyMetricsWebspaceVoiceSchema($schema);

        $this->createCmsPosts($schema);
        $this->createCmsSiteSettings($schema);
        $this->createBlogV2($schema);

        if ($isSqlite) {
            $this->createCmsModulesSqlite($schema);
            $this->createForumBaseSqlite($schema);
        } else {
            $this->createCmsModulesMysql($schema);
            $this->createForumBaseMysql($schema);
        }

        $this->extendCmsBlocks($schema);
        $this->extendCmsSiteSettings($schema);
        $this->extendSites($schema);
        $this->extendForumModeration($schema);
        $this->createAbuseLog($schema);
    }


    private function isSqlitePlatform(): bool
    {
        try {
            return $this->connection->getDatabasePlatform() instanceof SQLitePlatform;
        } catch (\Throwable) {
            return false;
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(true, 'Irreversible squashed migration.');
    }

    private function createCmsPosts(Schema $schema): void
    {
        if ($schema->hasTable('cms_posts') || !$schema->hasTable('sites')) {
            return;
        }

        $this->addSql("CREATE TABLE cms_posts (id INT AUTO_INCREMENT NOT NULL, site_id INT NOT NULL, title VARCHAR(180) NOT NULL, slug VARCHAR(180) NOT NULL, excerpt LONGTEXT DEFAULT NULL, content LONGTEXT NOT NULL, is_published TINYINT(1) NOT NULL, published_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX idx_cms_posts_site_id (site_id), UNIQUE INDEX uniq_cms_posts_site_slug (site_id, slug), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE cms_posts ADD CONSTRAINT FK_CMS_POSTS_SITE FOREIGN KEY (site_id) REFERENCES sites (id)');
    }

    private function createCmsSiteSettings(Schema $schema): void
    {
        if ($schema->hasTable('cms_site_settings') || !$schema->hasTable('sites')) {
            return;
        }

        $this->addSql("CREATE TABLE cms_site_settings (id INT AUTO_INCREMENT NOT NULL, site_id INT NOT NULL, active_theme VARCHAR(64) DEFAULT NULL, branding_json JSON DEFAULT NULL, module_toggles_json JSON DEFAULT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', UNIQUE INDEX uniq_cms_site_settings_site_id (site_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE cms_site_settings ADD CONSTRAINT FK_CMS_SITE_SETTINGS_SITE FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE');
    }

    private function createBlogV2(Schema $schema): void
    {
        if ($schema->hasTable('sites') && !$schema->hasTable('blog_categories')) {
            $this->addSql("CREATE TABLE blog_categories (id INT AUTO_INCREMENT NOT NULL, site_id INT NOT NULL, name VARCHAR(140) NOT NULL, slug VARCHAR(140) NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX idx_blog_categories_site_id (site_id), UNIQUE INDEX uniq_blog_categories_site_slug (site_id, slug), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
            $this->addSql('ALTER TABLE blog_categories ADD CONSTRAINT FK_BLOG_CATEGORIES_SITE FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE');
        }

        if ($schema->hasTable('sites') && !$schema->hasTable('blog_tags')) {
            $this->addSql("CREATE TABLE blog_tags (id INT AUTO_INCREMENT NOT NULL, site_id INT NOT NULL, name VARCHAR(140) NOT NULL, slug VARCHAR(140) NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX idx_blog_tags_site_id (site_id), UNIQUE INDEX uniq_blog_tags_site_slug (site_id, slug), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
            $this->addSql('ALTER TABLE blog_tags ADD CONSTRAINT FK_BLOG_TAGS_SITE FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE');
        }

        if ($schema->hasTable('cms_posts')) {
            $posts = $schema->getTable('cms_posts');

            if (!$posts->hasColumn('seo_title')) {
                $this->addSql('ALTER TABLE cms_posts ADD seo_title VARCHAR(180) DEFAULT NULL');
            }
            if (!$posts->hasColumn('seo_description')) {
                $this->addSql('ALTER TABLE cms_posts ADD seo_description LONGTEXT DEFAULT NULL');
            }
            if (!$posts->hasColumn('featured_image_path')) {
                $this->addSql('ALTER TABLE cms_posts ADD featured_image_path VARCHAR(255) DEFAULT NULL');
            }
            if (!$posts->hasColumn('category_id')) {
                $this->addSql('ALTER TABLE cms_posts ADD category_id INT DEFAULT NULL');
                $this->addSql('CREATE INDEX idx_cms_posts_category_id ON cms_posts (category_id)');
                if ($schema->hasTable('blog_categories')) {
                    $this->addSql('ALTER TABLE cms_posts ADD CONSTRAINT FK_CMS_POSTS_CATEGORY FOREIGN KEY (category_id) REFERENCES blog_categories (id) ON DELETE SET NULL');
                }
            }

            $this->addSql('UPDATE cms_posts SET seo_title = title WHERE seo_title IS NULL OR seo_title = ""');
            $this->addSql('UPDATE cms_posts SET seo_description = excerpt WHERE (seo_description IS NULL OR seo_description = "") AND excerpt IS NOT NULL AND excerpt <> ""');
        }

        if ($schema->hasTable('cms_posts') && $schema->hasTable('blog_tags') && !$schema->hasTable('blog_post_tags')) {
            $this->addSql('CREATE TABLE blog_post_tags (post_id INT NOT NULL, tag_id INT NOT NULL, INDEX idx_blog_post_tags_post_id (post_id), INDEX idx_blog_post_tags_tag_id (tag_id), PRIMARY KEY(post_id, tag_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE blog_post_tags ADD CONSTRAINT FK_BLOG_POST_TAGS_POST FOREIGN KEY (post_id) REFERENCES cms_posts (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE blog_post_tags ADD CONSTRAINT FK_BLOG_POST_TAGS_TAG FOREIGN KEY (tag_id) REFERENCES blog_tags (id) ON DELETE CASCADE');
        }
    }

    private function createCmsModulesMysql(Schema $schema): void
    {
        if ($schema->hasTable('sites') && !$schema->hasTable('cms_events')) {
            $this->addSql("CREATE TABLE cms_events (id INT AUTO_INCREMENT NOT NULL, site_id INT NOT NULL, title VARCHAR(180) NOT NULL, slug VARCHAR(180) NOT NULL, description LONGTEXT NOT NULL, start_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', end_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', location VARCHAR(180) DEFAULT NULL, status VARCHAR(32) NOT NULL, cover_image_path VARCHAR(255) DEFAULT NULL, is_published TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX idx_cms_events_site_start (site_id, start_at), UNIQUE INDEX uniq_cms_events_site_slug (site_id, slug), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
            $this->addSql('ALTER TABLE cms_events ADD CONSTRAINT FK_CMS_EVENTS_SITE FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE');
        }

        if ($schema->hasTable('sites') && !$schema->hasTable('team_members')) {
            $this->addSql("CREATE TABLE team_members (id INT AUTO_INCREMENT NOT NULL, site_id INT NOT NULL, name VARCHAR(140) NOT NULL, role_title VARCHAR(140) NOT NULL, bio LONGTEXT NOT NULL, avatar_path VARCHAR(255) DEFAULT NULL, socials_json JSON DEFAULT NULL, sort_order INT NOT NULL, is_active TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX idx_team_members_site_sort (site_id, sort_order), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
            $this->addSql('ALTER TABLE team_members ADD CONSTRAINT FK_TEAM_MEMBERS_SITE FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE');
        }

        if (!$schema->hasTable('media_assets')) {
            $this->addSql("CREATE TABLE media_assets (id INT AUTO_INCREMENT NOT NULL, site_id INT DEFAULT NULL, path VARCHAR(255) NOT NULL, title VARCHAR(180) DEFAULT NULL, alt VARCHAR(180) DEFAULT NULL, mime VARCHAR(120) DEFAULT NULL, size INT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX idx_media_assets_site_id (site_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
            if ($schema->hasTable('sites')) {
                $this->addSql('ALTER TABLE media_assets ADD CONSTRAINT FK_MEDIA_ASSETS_SITE FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE SET NULL');
            }
        }
    }

    private function createCmsModulesSqlite(Schema $schema): void
    {
        if ($schema->hasTable('sites') && !$schema->hasTable('cms_events')) {
            $this->addSql('CREATE TABLE cms_events (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, site_id INTEGER NOT NULL, title VARCHAR(180) NOT NULL, slug VARCHAR(180) NOT NULL, description CLOB NOT NULL, start_at DATETIME NOT NULL, end_at DATETIME DEFAULT NULL, location VARCHAR(180) DEFAULT NULL, status VARCHAR(32) NOT NULL, cover_image_path VARCHAR(255) DEFAULT NULL, is_published BOOLEAN NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, CONSTRAINT FK_CMS_EVENTS_SITE FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE)');
            $this->addSql('CREATE INDEX idx_cms_events_site_start ON cms_events (site_id, start_at)');
            $this->addSql('CREATE UNIQUE INDEX uniq_cms_events_site_slug ON cms_events (site_id, slug)');
        }

        if ($schema->hasTable('sites') && !$schema->hasTable('team_members')) {
            $this->addSql('CREATE TABLE team_members (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, site_id INTEGER NOT NULL, name VARCHAR(140) NOT NULL, role_title VARCHAR(140) NOT NULL, bio CLOB NOT NULL, avatar_path VARCHAR(255) DEFAULT NULL, socials_json CLOB DEFAULT NULL, sort_order INTEGER NOT NULL, is_active BOOLEAN NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, CONSTRAINT FK_TEAM_MEMBERS_SITE FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE)');
            $this->addSql('CREATE INDEX idx_team_members_site_sort ON team_members (site_id, sort_order)');
        }

        if (!$schema->hasTable('media_assets')) {
            $this->addSql('CREATE TABLE media_assets (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, site_id INTEGER DEFAULT NULL, path VARCHAR(255) NOT NULL, title VARCHAR(180) DEFAULT NULL, alt VARCHAR(180) DEFAULT NULL, mime VARCHAR(120) DEFAULT NULL, size INTEGER DEFAULT NULL, created_at DATETIME NOT NULL, CONSTRAINT FK_MEDIA_ASSETS_SITE FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE SET NULL)');
            $this->addSql('CREATE INDEX idx_media_assets_site_id ON media_assets (site_id)');
        }
    }

    private function extendCmsBlocks(Schema $schema): void
    {
        if (!$schema->hasTable('cms_blocks')) {
            return;
        }

        $table = $schema->getTable('cms_blocks');
        if (!$table->hasColumn('version')) {
            $this->addSql('ALTER TABLE cms_blocks ADD version INT NOT NULL DEFAULT 1');
            $this->addSql('UPDATE cms_blocks SET version = 1 WHERE version IS NULL');
        }
        if (!$table->hasColumn('payload_json')) {
            $this->addSql('ALTER TABLE cms_blocks ADD payload_json JSON DEFAULT NULL');
        }
        if (!$table->hasColumn('settings_json')) {
            $this->addSql('ALTER TABLE cms_blocks ADD settings_json JSON DEFAULT NULL');
        }
    }

    private function createForumBaseMysql(Schema $schema): void
    {
        if (!$schema->hasTable('sites') || !$schema->hasTable('users')) {
            return;
        }

        if (!$schema->hasTable('forum_categories')) {
            $this->addSql("CREATE TABLE forum_categories (id INT AUTO_INCREMENT NOT NULL, site_id INT NOT NULL, title VARCHAR(160) NOT NULL, slug VARCHAR(160) NOT NULL, sort_order INT NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX idx_forum_categories_site_sort (site_id, sort_order), UNIQUE INDEX uniq_forum_categories_site_slug (site_id, slug), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
            $this->addSql('ALTER TABLE forum_categories ADD CONSTRAINT FK_FORUM_CATEGORIES_SITE FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE');
        }

        if (!$schema->hasTable('forum_boards')) {
            $this->addSql("CREATE TABLE forum_boards (id INT AUTO_INCREMENT NOT NULL, category_id INT NOT NULL, site_id INT NOT NULL, title VARCHAR(160) NOT NULL, slug VARCHAR(160) NOT NULL, description LONGTEXT DEFAULT NULL, sort_order INT NOT NULL, is_active TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX idx_forum_boards_category (category_id), UNIQUE INDEX uniq_forum_boards_site_slug (site_id, slug), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
            $this->addSql('ALTER TABLE forum_boards ADD CONSTRAINT FK_FORUM_BOARDS_CATEGORY FOREIGN KEY (category_id) REFERENCES forum_categories (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE forum_boards ADD CONSTRAINT FK_FORUM_BOARDS_SITE FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE');
        }

        if (!$schema->hasTable('forum_threads')) {
            $this->addSql("CREATE TABLE forum_threads (id INT AUTO_INCREMENT NOT NULL, board_id INT NOT NULL, site_id INT NOT NULL, author_user_id INT DEFAULT NULL, title VARCHAR(180) NOT NULL, slug VARCHAR(180) NOT NULL, is_pinned TINYINT(1) NOT NULL, is_closed TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', last_post_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX idx_forum_threads_board_lastpost (board_id, last_post_at), INDEX IDX_9A57D33F97E9E282 (author_user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
            $this->addSql('ALTER TABLE forum_threads ADD CONSTRAINT FK_FORUM_THREADS_BOARD FOREIGN KEY (board_id) REFERENCES forum_boards (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE forum_threads ADD CONSTRAINT FK_FORUM_THREADS_SITE FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE forum_threads ADD CONSTRAINT FK_FORUM_THREADS_AUTHOR FOREIGN KEY (author_user_id) REFERENCES users (id) ON DELETE SET NULL');
        }

        if (!$schema->hasTable('forum_posts')) {
            $this->addSql("CREATE TABLE forum_posts (id INT AUTO_INCREMENT NOT NULL, thread_id INT NOT NULL, site_id INT NOT NULL, author_user_id INT DEFAULT NULL, content LONGTEXT NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', is_deleted TINYINT(1) NOT NULL, INDEX idx_forum_posts_thread_created (thread_id, created_at), INDEX IDX_C960C4A697E9E282 (author_user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
            $this->addSql('ALTER TABLE forum_posts ADD CONSTRAINT FK_FORUM_POSTS_THREAD FOREIGN KEY (thread_id) REFERENCES forum_threads (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE forum_posts ADD CONSTRAINT FK_FORUM_POSTS_SITE FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE forum_posts ADD CONSTRAINT FK_FORUM_POSTS_AUTHOR FOREIGN KEY (author_user_id) REFERENCES users (id) ON DELETE SET NULL');
        }
    }

    private function createForumBaseSqlite(Schema $schema): void
    {
        if (!$schema->hasTable('sites') || !$schema->hasTable('users')) {
            return;
        }

        if (!$schema->hasTable('forum_categories')) {
            $this->addSql('CREATE TABLE forum_categories (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, site_id INTEGER NOT NULL, title VARCHAR(160) NOT NULL, slug VARCHAR(160) NOT NULL, sort_order INTEGER NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, CONSTRAINT FK_FORUM_CATEGORIES_SITE FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE)');
            $this->addSql('CREATE INDEX idx_forum_categories_site_sort ON forum_categories (site_id, sort_order)');
            $this->addSql('CREATE UNIQUE INDEX uniq_forum_categories_site_slug ON forum_categories (site_id, slug)');
        }

        if (!$schema->hasTable('forum_boards')) {
            $this->addSql('CREATE TABLE forum_boards (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, category_id INTEGER NOT NULL, site_id INTEGER NOT NULL, title VARCHAR(160) NOT NULL, slug VARCHAR(160) NOT NULL, description CLOB DEFAULT NULL, sort_order INTEGER NOT NULL DEFAULT 0, is_active BOOLEAN NOT NULL DEFAULT 1, created_at DATETIME NOT NULL, CONSTRAINT FK_FORUM_BOARDS_CATEGORY FOREIGN KEY (category_id) REFERENCES forum_categories (id) ON DELETE CASCADE, CONSTRAINT FK_FORUM_BOARDS_SITE FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE)');
            $this->addSql('CREATE INDEX idx_forum_boards_category ON forum_boards (category_id)');
            $this->addSql('CREATE UNIQUE INDEX uniq_forum_boards_site_slug ON forum_boards (site_id, slug)');
        }

        if (!$schema->hasTable('forum_threads')) {
            $this->addSql('CREATE TABLE forum_threads (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, board_id INTEGER NOT NULL, site_id INTEGER NOT NULL, author_user_id INTEGER DEFAULT NULL, title VARCHAR(180) NOT NULL, slug VARCHAR(180) NOT NULL, is_pinned BOOLEAN NOT NULL DEFAULT 0, is_closed BOOLEAN NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, last_post_at DATETIME NOT NULL, CONSTRAINT FK_FORUM_THREADS_BOARD FOREIGN KEY (board_id) REFERENCES forum_boards (id) ON DELETE CASCADE, CONSTRAINT FK_FORUM_THREADS_SITE FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE, CONSTRAINT FK_FORUM_THREADS_AUTHOR FOREIGN KEY (author_user_id) REFERENCES users (id) ON DELETE SET NULL)');
            $this->addSql('CREATE INDEX idx_forum_threads_board_lastpost ON forum_threads (board_id, last_post_at)');
            $this->addSql('CREATE INDEX idx_forum_threads_author ON forum_threads (author_user_id)');
        }

        if (!$schema->hasTable('forum_posts')) {
            $this->addSql('CREATE TABLE forum_posts (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, thread_id INTEGER NOT NULL, site_id INTEGER NOT NULL, author_user_id INTEGER DEFAULT NULL, content CLOB NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, is_deleted BOOLEAN NOT NULL DEFAULT 0, CONSTRAINT FK_FORUM_POSTS_THREAD FOREIGN KEY (thread_id) REFERENCES forum_threads (id) ON DELETE CASCADE, CONSTRAINT FK_FORUM_POSTS_SITE FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE, CONSTRAINT FK_FORUM_POSTS_AUTHOR FOREIGN KEY (author_user_id) REFERENCES users (id) ON DELETE SET NULL)');
            $this->addSql('CREATE INDEX idx_forum_posts_thread_created ON forum_posts (thread_id, created_at)');
            $this->addSql('CREATE INDEX idx_forum_posts_author ON forum_posts (author_user_id)');
        }
    }

    private function extendCmsSiteSettings(Schema $schema): void
    {
        if (!$schema->hasTable('cms_site_settings')) {
            return;
        }

        $table = $schema->getTable('cms_site_settings');

        if (!$table->hasColumn('header_links_json')) {
            $this->addSql('ALTER TABLE cms_site_settings ADD header_links_json JSON DEFAULT NULL');
        }
        if (!$table->hasColumn('footer_links_json')) {
            $this->addSql('ALTER TABLE cms_site_settings ADD footer_links_json JSON DEFAULT NULL');
        }
    }

    private function extendSites(Schema $schema): void
    {
        if (!$schema->hasTable('sites')) {
            return;
        }

        $table = $schema->getTable('sites');

        if (!$table->hasColumn('maintenance_graphic_path')) {
            $this->addSql('ALTER TABLE sites ADD maintenance_graphic_path VARCHAR(255) DEFAULT NULL');
        }
    }

    private function extendForumModeration(Schema $schema): void
    {
        if ($schema->hasTable('forum_threads')) {
            $threads = $schema->getTable('forum_threads');

            if (!$threads->hasColumn('last_activity_at')) {
                $this->addSql("ALTER TABLE forum_threads ADD last_activity_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
                $this->addSql('UPDATE forum_threads SET last_activity_at = last_post_at WHERE last_activity_at IS NULL');
                $this->addSql("ALTER TABLE forum_threads MODIFY last_activity_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)'");
            }

            if (!$threads->hasIndex('idx_forum_threads_board_activity')) {
                $this->addSql('CREATE INDEX idx_forum_threads_board_activity ON forum_threads (board_id, last_activity_at)');
            }
        }

        if ($schema->hasTable('forum_posts')) {
            $posts = $schema->getTable('forum_posts');

            if (!$posts->hasColumn('deleted_at')) {
                $this->addSql("ALTER TABLE forum_posts ADD deleted_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
            }
            if (!$posts->hasColumn('deleted_by_id')) {
                $this->addSql('ALTER TABLE forum_posts ADD deleted_by_id INT DEFAULT NULL');
            }
            if ($posts->hasColumn('deleted_by_id') && !$posts->hasIndex('IDX_FORUM_POSTS_DELETED_BY')) {
                $this->addSql('CREATE INDEX IDX_FORUM_POSTS_DELETED_BY ON forum_posts (deleted_by_id)');
            }
            if ($posts->hasColumn('deleted_by_id') && !$this->hasForeignKey($posts, 'FK_FORUM_POSTS_DELETED_BY')) {
                $this->addSql('ALTER TABLE forum_posts ADD CONSTRAINT FK_FORUM_POSTS_DELETED_BY FOREIGN KEY (deleted_by_id) REFERENCES users (id) ON DELETE SET NULL');
            }
        }

        if (!$schema->hasTable('forum_post_reports') && $schema->hasTable('forum_posts')) {
            $this->addSql('CREATE TABLE forum_post_reports (id INT AUTO_INCREMENT NOT NULL, post_id INT NOT NULL, reporter_id INT DEFAULT NULL, resolved_by_id INT DEFAULT NULL, reason VARCHAR(120) NOT NULL, details LONGTEXT DEFAULT NULL, status VARCHAR(20) NOT NULL, reporter_ip_hash VARCHAR(64) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', resolved_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_forum_reports_status_created (status, created_at), INDEX IDX_9E8A7A614B89032C (post_id), INDEX IDX_9E8A7A61E1F7CC78 (reporter_id), INDEX IDX_9E8A7A61424E58B2 (resolved_by_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE forum_post_reports ADD CONSTRAINT FK_9E8A7A614B89032C FOREIGN KEY (post_id) REFERENCES forum_posts (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE forum_post_reports ADD CONSTRAINT FK_9E8A7A61E1F7CC78 FOREIGN KEY (reporter_id) REFERENCES users (id) ON DELETE SET NULL');
            $this->addSql('ALTER TABLE forum_post_reports ADD CONSTRAINT FK_9E8A7A61424E58B2 FOREIGN KEY (resolved_by_id) REFERENCES users (id) ON DELETE SET NULL');
        }

        if (!$schema->hasTable('forum_member_bans') && $schema->hasTable('users')) {
            $this->addSql('CREATE TABLE forum_member_bans (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, banned_until DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', reason VARCHAR(255) DEFAULT NULL, UNIQUE INDEX uniq_forum_member_bans_user (user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE forum_member_bans ADD CONSTRAINT FK_FORUM_MEMBER_BANS_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        }
    }

    private function applyGameserverHardening(Schema $schema): void
    {
        if ($schema->hasTable('instance_sftp_credentials')) {
            $table = $schema->getTable('instance_sftp_credentials');
            if (!$table->hasColumn('rotated_at')) {
                $this->addSql("ALTER TABLE instance_sftp_credentials ADD rotated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
            }
            if (!$table->hasColumn('expires_at')) {
                $this->addSql("ALTER TABLE instance_sftp_credentials ADD expires_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
            }
            $this->addSql('UPDATE instance_sftp_credentials SET rotated_at = COALESCE(updated_at, created_at) WHERE rotated_at IS NULL');
        }

        if ($schema->hasTable('backups')) {
            $table = $schema->getTable('backups');
            if (!$table->hasColumn('size_bytes')) {
                $this->addSql('ALTER TABLE backups ADD size_bytes BIGINT DEFAULT NULL');
            }
            if (!$table->hasColumn('checksum_sha256')) {
                $this->addSql('ALTER TABLE backups ADD checksum_sha256 VARCHAR(128) DEFAULT NULL');
            }
            if (!$table->hasColumn('archive_path')) {
                $this->addSql('ALTER TABLE backups ADD archive_path VARCHAR(1024) DEFAULT NULL');
            }
            if (!$table->hasColumn('error_code')) {
                $this->addSql('ALTER TABLE backups ADD error_code VARCHAR(120) DEFAULT NULL');
            }
            if (!$table->hasColumn('error_message')) {
                $this->addSql('ALTER TABLE backups ADD error_message LONGTEXT DEFAULT NULL');
            }
        }

        if ($schema->hasTable('backup_targets')) {
            $table = $schema->getTable('backup_targets');
            if (!$table->hasColumn('enabled')) {
                $this->addSql('ALTER TABLE backup_targets ADD enabled TINYINT(1) NOT NULL DEFAULT 1');
            }
        }

        if ($schema->hasTable('backup_schedules')) {
            $table = $schema->getTable('backup_schedules');
            if (!$table->hasColumn('time_zone')) {
                $this->addSql("ALTER TABLE backup_schedules ADD time_zone VARCHAR(100) NOT NULL DEFAULT 'UTC'");
            }
            if (!$table->hasColumn('compression')) {
                $this->addSql("ALTER TABLE backup_schedules ADD compression VARCHAR(32) NOT NULL DEFAULT 'gzip'");
            }
            if (!$table->hasColumn('stop_before')) {
                $this->addSql('ALTER TABLE backup_schedules ADD stop_before TINYINT(1) NOT NULL DEFAULT 0');
            }
            if (!$table->hasColumn('backup_target_id')) {
                $this->addSql('ALTER TABLE backup_schedules ADD backup_target_id INT DEFAULT NULL');
            }
            if (!$table->hasColumn('last_run_at')) {
                $this->addSql("ALTER TABLE backup_schedules ADD last_run_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
            }
            if (!$table->hasColumn('last_status')) {
                $this->addSql('ALTER TABLE backup_schedules ADD last_status VARCHAR(32) DEFAULT NULL');
            }
            if (!$table->hasColumn('last_error_code')) {
                $this->addSql('ALTER TABLE backup_schedules ADD last_error_code VARCHAR(64) DEFAULT NULL');
            }

            if (!$table->hasIndex('IDX_79FF3B0A62BAA4E5')) {
                $this->addSql('CREATE INDEX IDX_79FF3B0A62BAA4E5 ON backup_schedules (backup_target_id)');
            }

            if (!$this->hasForeignKey($table, 'FK_79FF3B0A62BAA4E5')) {
                $this->addSql('ALTER TABLE backup_schedules ADD CONSTRAINT FK_79FF3B0A62BAA4E5 FOREIGN KEY (backup_target_id) REFERENCES backup_targets (id) ON DELETE SET NULL');
            }
        }

        if ($schema->hasTable('instance_schedules')) {
            $table = $schema->getTable('instance_schedules');
            if (!$table->hasColumn('last_run_at')) {
                $this->addSql("ALTER TABLE instance_schedules ADD last_run_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
            }
            if (!$table->hasColumn('last_status')) {
                $this->addSql('ALTER TABLE instance_schedules ADD last_status VARCHAR(32) DEFAULT NULL');
            }
            if (!$table->hasColumn('last_error_code')) {
                $this->addSql('ALTER TABLE instance_schedules ADD last_error_code VARCHAR(64) DEFAULT NULL');
            }
        }
    }

    private function removeTeamSpeakTemplatesAndDeduplicate(Schema $schema): void
    {
        if (!$schema->hasTable('game_templates')) {
            return;
        }

        $duplicates = $this->connection->fetchAllAssociative(
            'SELECT game_key, MIN(id) AS keep_id FROM game_templates WHERE game_key IS NOT NULL GROUP BY game_key HAVING COUNT(*) > 1'
        );

        foreach ($duplicates as $row) {
            $gameKey = (string) $row['game_key'];
            $keepId = (int) $row['keep_id'];
            $rows = $this->connection->fetchFirstColumn(
                'SELECT id FROM game_templates WHERE game_key = ? AND id <> ?',
                [$gameKey, $keepId]
            );

            foreach ($rows as $dropId) {
                $this->addSql('UPDATE instances SET template_id = ? WHERE template_id = ?', [$keepId, (int) $dropId]);
                $this->addSql('DELETE FROM game_template_plugins WHERE template_id = ?', [(int) $dropId]);
                $this->addSql('DELETE FROM game_templates WHERE id = ?', [(int) $dropId]);
            }
        }

        $teamspeakIds = $this->connection->fetchFirstColumn(
            "SELECT id FROM game_templates WHERE game_key IN ('teamspeak3', 'teamspeak3_windows')"
        );

        foreach ($teamspeakIds as $templateId) {
            $this->addSql('DELETE FROM game_template_plugins WHERE template_id = ?', [(int) $templateId]);
            $this->addSql('DELETE FROM instances WHERE template_id = ?', [(int) $templateId]);
            $this->addSql('DELETE FROM game_templates WHERE id = ?', [(int) $templateId]);
        }
    }

    private function applyDatabaseSelfServiceHardening(Schema $schema): void
    {
        if ($schema->hasTable('database_nodes')) {
            $table = $schema->getTable('database_nodes');
            if (!$table->hasColumn('tls_mode')) {
                $this->addSql("ALTER TABLE database_nodes ADD tls_mode VARCHAR(20) DEFAULT 'off' NOT NULL");
            }
            if (!$table->hasColumn('ca_cert')) {
                $this->addSql('ALTER TABLE database_nodes ADD ca_cert LONGTEXT DEFAULT NULL');
            }
            if (!$table->hasColumn('tags')) {
                $this->addSql('ALTER TABLE database_nodes ADD tags JSON DEFAULT NULL');
            }
            if (!$table->hasColumn('admin_user')) {
                $this->addSql('ALTER TABLE database_nodes ADD admin_user VARCHAR(190) DEFAULT NULL');
            }
            if (!$table->hasColumn('encrypted_admin_secret')) {
                $this->addSql('ALTER TABLE database_nodes ADD encrypted_admin_secret JSON DEFAULT NULL');
            }
        }

        if ($schema->hasTable('databases')) {
            $table = $schema->getTable('databases');
            if (!$table->hasColumn('status')) {
                $this->addSql("ALTER TABLE `databases` ADD status VARCHAR(20) DEFAULT 'pending' NOT NULL");
            }
            if (!$table->hasColumn('last_error_code')) {
                $this->addSql('ALTER TABLE `databases` ADD last_error_code VARCHAR(120) DEFAULT NULL');
            }
            if (!$table->hasColumn('last_error_message')) {
                $this->addSql('ALTER TABLE `databases` ADD last_error_message LONGTEXT DEFAULT NULL');
            }
            if (!$table->hasColumn('rotated_at')) {
                $this->addSql('ALTER TABLE `databases` ADD rotated_at DATETIME DEFAULT NULL COMMENT "(DC2Type:datetime_immutable)"');
            }
            if (!$table->hasColumn('expires_at')) {
                $this->addSql('ALTER TABLE `databases` ADD expires_at DATETIME DEFAULT NULL COMMENT "(DC2Type:datetime_immutable)"');
            }
            if ($table->hasColumn('encrypted_password')) {
                $this->addSql('ALTER TABLE `databases` CHANGE `encrypted_password` `encrypted_password` JSON DEFAULT NULL');
            }
        }
    }


    private function applyMetricsWebspaceVoiceSchema(Schema $schema): void
    {
        if (!$schema->hasTable('instance_metric_samples')) {
            $this->addSql('CREATE TABLE instance_metric_samples (id INT AUTO_INCREMENT NOT NULL, instance_id INT NOT NULL, cpu_percent DOUBLE PRECISION DEFAULT NULL, mem_used_bytes BIGINT DEFAULT NULL, tasks_current INT DEFAULT NULL, collected_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', error_code VARCHAR(120) DEFAULT NULL, INDEX idx_instance_metric_samples_instance_collected (instance_id, collected_at), INDEX IDX_D9719841B6BD1646 (instance_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }

        if ($schema->hasTable('instances') && $schema->hasTable('instance_metric_samples')) {
            $table = $schema->getTable('instance_metric_samples');
            if (!$this->hasForeignKey($table, 'FK_D9719841B6BD1646')) {
                $this->addSql('ALTER TABLE instance_metric_samples ADD CONSTRAINT FK_D9719841B6BD1646 FOREIGN KEY (instance_id) REFERENCES instances (id) ON DELETE CASCADE');
            }
        }

        if (!$schema->hasTable('webspace_nodes')) {
            $this->addSql('CREATE TABLE webspace_nodes (id INT AUTO_INCREMENT NOT NULL, agent_id VARCHAR(64) NOT NULL, name VARCHAR(120) NOT NULL, host VARCHAR(255) NOT NULL, enabled TINYINT(1) DEFAULT 1 NOT NULL, webserver_type VARCHAR(20) NOT NULL, base_path VARCHAR(255) NOT NULL, vhost_paths JSON NOT NULL, php_fpm_mode VARCHAR(50) DEFAULT NULL, default_templates JSON DEFAULT NULL, tls_defaults JSON DEFAULT NULL, created_at DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)", updated_at DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)", INDEX idx_webspace_nodes_agent_id (agent_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }

        if ($schema->hasTable('webspaces')) {
            $table = $schema->getTable('webspaces');
            if (!$table->hasColumn('apply_status')) {
                $this->addSql("ALTER TABLE webspaces ADD apply_status VARCHAR(20) DEFAULT 'pending' NOT NULL");
            }
            if (!$table->hasColumn('apply_required')) {
                $this->addSql('ALTER TABLE webspaces ADD apply_required TINYINT(1) DEFAULT 0 NOT NULL');
            }
            if (!$table->hasColumn('last_apply_error_code')) {
                $this->addSql('ALTER TABLE webspaces ADD last_apply_error_code VARCHAR(64) DEFAULT NULL');
            }
            if (!$table->hasColumn('last_apply_error_message')) {
                $this->addSql('ALTER TABLE webspaces ADD last_apply_error_message LONGTEXT DEFAULT NULL');
            }
            if (!$table->hasColumn('last_applied_hash')) {
                $this->addSql('ALTER TABLE webspaces ADD last_applied_hash VARCHAR(64) DEFAULT NULL');
            }
            if (!$table->hasColumn('last_applied_at')) {
                $this->addSql('ALTER TABLE webspaces ADD last_applied_at DATETIME DEFAULT NULL COMMENT "(DC2Type:datetime_immutable)"');
            }
            if (!$table->hasColumn('runtime')) {
                $this->addSql("ALTER TABLE webspaces ADD runtime VARCHAR(20) DEFAULT 'nginx' NOT NULL");
            }
        }

        if ($schema->hasTable('domains')) {
            $table = $schema->getTable('domains');
            if (!$table->hasColumn('type')) {
                $this->addSql("ALTER TABLE domains ADD type VARCHAR(20) DEFAULT 'domain' NOT NULL");
            }
            if (!$table->hasColumn('target_path')) {
                $this->addSql('ALTER TABLE domains ADD target_path VARCHAR(255) DEFAULT NULL');
            }
            if (!$table->hasColumn('redirect_https')) {
                $this->addSql('ALTER TABLE domains ADD redirect_https TINYINT(1) DEFAULT 0 NOT NULL');
            }
            if (!$table->hasColumn('redirect_www')) {
                $this->addSql('ALTER TABLE domains ADD redirect_www TINYINT(1) DEFAULT 0 NOT NULL');
            }
            if (!$table->hasColumn('apply_status')) {
                $this->addSql("ALTER TABLE domains ADD apply_status VARCHAR(20) DEFAULT 'pending' NOT NULL");
            }
            if (!$table->hasColumn('last_error_code')) {
                $this->addSql('ALTER TABLE domains ADD last_error_code VARCHAR(64) DEFAULT NULL');
            }
            if (!$table->hasColumn('last_error_message')) {
                $this->addSql('ALTER TABLE domains ADD last_error_message LONGTEXT DEFAULT NULL');
            }
            if (!$table->hasColumn('last_applied_at')) {
                $this->addSql('ALTER TABLE domains ADD last_applied_at DATETIME DEFAULT NULL COMMENT "(DC2Type:datetime_immutable)"');
            }
        }

        if (!$schema->hasTable('voice_nodes')) {
            $this->addSql('CREATE TABLE voice_nodes (id INT AUTO_INCREMENT NOT NULL, provider_type VARCHAR(20) NOT NULL, host VARCHAR(255) NOT NULL, query_port INT NOT NULL, credentials_encrypted JSON DEFAULT NULL, enabled TINYINT(1) DEFAULT 1 NOT NULL, name VARCHAR(120) NOT NULL, created_at DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)", updated_at DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)", PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }

        if (!$schema->hasTable('voice_instances')) {
            $this->addSql('CREATE TABLE voice_instances (id INT AUTO_INCREMENT NOT NULL, customer_id INT NOT NULL, node_id INT NOT NULL, external_id VARCHAR(64) NOT NULL, name VARCHAR(120) NOT NULL, status VARCHAR(20) NOT NULL, players_online INT DEFAULT NULL, players_max INT DEFAULT NULL, reason LONGTEXT DEFAULT NULL, error_code VARCHAR(64) DEFAULT NULL, checked_at DATETIME DEFAULT NULL COMMENT "(DC2Type:datetime_immutable)", created_at DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)", updated_at DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)", INDEX IDX_14580A3A9395C3F3 (customer_id), INDEX IDX_14580A3A5E237E06 (node_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }

        if ($schema->hasTable('voice_instances') && $schema->hasTable('users') && $schema->hasTable('voice_nodes')) {
            $table = $schema->getTable('voice_instances');
            if (!$this->hasForeignKey($table, 'FK_14580A3A9395C3F3')) {
                $this->addSql('ALTER TABLE voice_instances ADD CONSTRAINT FK_14580A3A9395C3F3 FOREIGN KEY (customer_id) REFERENCES users (id) ON DELETE CASCADE');
            }
            if (!$this->hasForeignKey($table, 'FK_14580A3A5E237E06')) {
                $this->addSql('ALTER TABLE voice_instances ADD CONSTRAINT FK_14580A3A5E237E06 FOREIGN KEY (node_id) REFERENCES voice_nodes (id) ON DELETE CASCADE');
            }
        }

        if (!$schema->hasTable('voice_rate_limit_states')) {
            $this->addSql('CREATE TABLE voice_rate_limit_states (id INT AUTO_INCREMENT NOT NULL, node_id INT NOT NULL, provider_type VARCHAR(20) NOT NULL, tokens DOUBLE PRECISION NOT NULL, locked_until DATETIME DEFAULT NULL COMMENT "(DC2Type:datetime_immutable)", consecutive_failures INT DEFAULT 0 NOT NULL, circuit_open_until DATETIME DEFAULT NULL COMMENT "(DC2Type:datetime_immutable)", updated_at DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)", INDEX IDX_EFAE8D0A5E237E06 (node_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }

        if ($schema->hasTable('voice_rate_limit_states') && $schema->hasTable('voice_nodes')) {
            $table = $schema->getTable('voice_rate_limit_states');
            if (!$this->hasForeignKey($table, 'FK_EFAE8D0A5E237E06')) {
                $this->addSql('ALTER TABLE voice_rate_limit_states ADD CONSTRAINT FK_EFAE8D0A5E237E06 FOREIGN KEY (node_id) REFERENCES voice_nodes (id) ON DELETE CASCADE');
            }
        }
    }

    private function createAbuseLog(Schema $schema): void
    {
        if ($schema->hasTable('abuse_log')) {
            return;
        }

        $this->addSql("CREATE TABLE abuse_log (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(80) NOT NULL, ip_hash VARCHAR(64) DEFAULT NULL, ua_hash VARCHAR(64) DEFAULT NULL, email_hash VARCHAR(64) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX idx_abuse_log_type_created (type, created_at), INDEX idx_abuse_log_ip_created (ip_hash, created_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
    }


    private function hasForeignKey(Table $table, string $fkName): bool
    {
        foreach ($table->getForeignKeys() as $foreignKey) {
            if ($foreignKey->getName() === $fkName) {
                return true;
            }
        }

        return false;
    }
}
