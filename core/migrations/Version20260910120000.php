<?php

declare(strict_types=1);

namespace DoctrineMigrations;

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
        $isSqlite = $this->connection->getDatabasePlatform() instanceof SQLitePlatform;

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
            $this->addSql("CREATE TABLE blog_post_tags (post_id INT NOT NULL, tag_id INT NOT NULL, INDEX idx_blog_post_tags_post_id (post_id), INDEX idx_blog_post_tags_tag_id (tag_id), PRIMARY KEY(post_id, tag_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
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
