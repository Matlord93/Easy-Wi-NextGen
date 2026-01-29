<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250215090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create users table required for foreign keys.';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('users')) {
            return;
        }

        $this->addSql('CREATE TABLE users (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, password_hash VARCHAR(255) NOT NULL, type VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', email_verified_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', email_verification_token_hash VARCHAR(64) DEFAULT NULL, email_verification_expires_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', terms_accepted_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', terms_accepted_ip VARCHAR(45) DEFAULT NULL, privacy_accepted_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', privacy_accepted_ip VARCHAR(45) DEFAULT NULL, reseller_id INT DEFAULT NULL, UNIQUE INDEX uniq_users_email (email), INDEX idx_users_email_verification_token (email_verification_token_hash), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('users')) {
            return;
        }

        $this->addSql('DROP TABLE users');
    }
}

final class Version20260615120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align CS2 start params and server.cfg defaults with customer-configured values.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('game_templates')) {
            return;
        }

        $startParams = '{{INSTANCE_DIR}}/game/cs2.sh -dedicated +ip 0.0.0.0 -port {{PORT_GAME}} +maxplayers {{MAX_PLAYERS}} +map {{MAP}} -tickrate {{TICKRATE}} +servercfgfile server.cfg -condebug +sv_logfile 1 +game_type {{GAME_TYPE}} +game_mode {{GAME_MODE}} +sv_setsteamaccount {{STEAM_GSLT}}';
        $startParamsWindows = '{{INSTANCE_DIR}}/game/bin/win64/cs2.exe -dedicated +ip 0.0.0.0 -port {{PORT_GAME}} +maxplayers {{MAX_PLAYERS}} +map {{MAP}} -tickrate {{TICKRATE}} +servercfgfile server.cfg -condebug +sv_logfile 1 +game_type {{GAME_TYPE}} +game_mode {{GAME_MODE}} +sv_setsteamaccount {{STEAM_GSLT}}';

        $configFiles = [
            [
                'path' => 'game/csgo/cfg/server.cfg',
                'description' => 'Base server configuration',
                'contents' => "hostname \"{{SERVER_NAME}}\"\nrcon_password \"{{RCON_PASSWORD}}\"\nsv_password \"{{SERVER_PASSWORD}}\"                // optional: Passwort für Spieler (leer = öffentlich)\n",
            ],
        ];

        $requiredPorts = [
            ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
        ];

        $this->updateTemplate('cs2', $startParams, $configFiles, $requiredPorts, [
            ['key' => 'MAX_PLAYERS', 'value' => '16'],
            ['key' => 'MAP', 'value' => 'de_dust2'],
            ['key' => 'TICKRATE', 'value' => '128'],
            ['key' => 'GAME_TYPE', 'value' => '0'],
            ['key' => 'GAME_MODE', 'value' => '0'],
        ]);
        $this->updateTemplate('cs2_windows', $startParamsWindows, $configFiles, $requiredPorts, [
            ['key' => 'MAX_PLAYERS', 'value' => '16'],
            ['key' => 'MAP', 'value' => 'de_dust2'],
            ['key' => 'TICKRATE', 'value' => '128'],
            ['key' => 'GAME_TYPE', 'value' => '0'],
            ['key' => 'GAME_MODE', 'value' => '0'],
        ]);
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('game_templates')) {
            return;
        }

        $this->addSql(sprintf(
            'UPDATE game_templates SET start_params = %s WHERE game_key = %s',
            $this->quote('{{INSTANCE_DIR}}/game/cs2.sh -port {{PORT_GAME}} +sv_queryport {{PORT_QUERY}} +rcon_port {{PORT_RCON}} +tv_port {{PORT_TV}} +maxplayers {{MAX_PLAYERS}} +map de_dust2 +sv_setsteamaccount {{STEAM_GSLT}} +hostname "{{SERVER_NAME}}" +rcon_password "{{RCON_PASSWORD}}"'),
            $this->quote('cs2'),
        ));
        $this->addSql(sprintf(
            'UPDATE game_templates SET start_params = %s WHERE game_key = %s',
            $this->quote('{{INSTANCE_DIR}}/game/bin/win64/cs2.exe -dedicated -console -usercon -tickrate 128 -port {{PORT_GAME}} +sv_queryport {{PORT_QUERY}} +rcon_port {{PORT_RCON}} +tv_port {{PORT_TV}} +map de_dust2 +sv_setsteamaccount {{STEAM_GSLT}} +hostname "{{SERVER_NAME}}" +rcon_password "{{RCON_PASSWORD}}"'),
            $this->quote('cs2_windows'),
        ));
    }

    /**
     * @param array<int, array{key: string, value: string}> $requiredVars
     * @param array<int, array<string, mixed>> $configFiles
     * @param array<int, array<string, mixed>> $requiredPorts
     */
    private function updateTemplate(string $gameKey, string $startParams, array $configFiles, array $requiredPorts, array $requiredVars): void
    {
        $this->addSql(sprintf(
            'UPDATE game_templates SET start_params = %s, config_files = %s, required_ports = %s WHERE game_key = %s',
            $this->quote($startParams),
            $this->quoteJson($configFiles),
            $this->quoteJson($requiredPorts),
            $this->quote($gameKey),
        ));

        $templates = $this->connection->fetchAllAssociative(
            'SELECT id, env_vars FROM game_templates WHERE game_key = ' . $this->quote($gameKey),
        );
        foreach ($templates as $template) {
            $envVars = $this->decodeJsonArray((string) ($template['env_vars'] ?? '[]'));
            foreach ($requiredVars as $requiredVar) {
                $envVars = $this->ensureEnvVar($envVars, $requiredVar['key'], $requiredVar['value']);
            }

            $this->addSql(sprintf(
                'UPDATE game_templates SET env_vars = %s WHERE id = %d',
                $this->quoteJson($envVars),
                (int) $template['id'],
            ));
        }
    }

    /**
     * @param array<int, mixed> $envVars
     *
     * @return array<int, mixed>
     */
    private function ensureEnvVar(array $envVars, string $key, string $value): array
    {
        foreach ($envVars as $entry) {
            if (is_array($entry) && (string) ($entry['key'] ?? '') === $key) {
                return $envVars;
            }
        }

        $envVars[] = ['key' => $key, 'value' => $value];

        return $envVars;
    }

    private function decodeJsonArray(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function quote(?string $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        return $this->connection->quote($value);
    }

    private function quoteJson(array $value): string
    {
        return $this->quote(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}


final class Version20250215120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add public_servers table for manual server directory entries.';
    }

    public function up(Schema $schema): void
    {
$this->addSql('
    CREATE TABLE public_servers (
        id INT AUTO_INCREMENT NOT NULL,
        created_by_id INT NOT NULL,
        site_id INT NOT NULL,
        name VARCHAR(160) NOT NULL,
        category VARCHAR(80) NOT NULL,
        game_key VARCHAR(120) NOT NULL,
        ip VARCHAR(64) NOT NULL,
        port INT NOT NULL,
        query_type VARCHAR(40) NOT NULL,
        query_port INT DEFAULT NULL,
        visible_public TINYINT(1) NOT NULL,
        visible_logged_in TINYINT(1) NOT NULL,
        sort_order INT NOT NULL,
        notes_internal LONGTEXT DEFAULT NULL,
        status_cache JSON NOT NULL,
        last_checked_at DATETIME DEFAULT NULL,
        check_interval_seconds INT NOT NULL,
        INDEX idx_public_servers_site_id (site_id),
        INDEX idx_public_servers_visibility (visible_public, visible_logged_in),
        INDEX idx_public_servers_game_key (game_key),
        INDEX idx_public_servers_created_by (created_by_id),
        PRIMARY KEY(id)
    ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
');
        $this->addSql('ALTER TABLE public_servers ADD CONSTRAINT fk_public_servers_created_by FOREIGN KEY (created_by_id) REFERENCES users (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE public_servers DROP FOREIGN KEY fk_public_servers_created_by');
        $this->addSql('DROP TABLE public_servers');
    }
}

final class Version20250305110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add shop categories, products, orders, and rentals for prepaid shop.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('shop_categories')) {
            $this->addSql('CREATE TABLE shop_categories (id INT AUTO_INCREMENT NOT NULL, site_id INT NOT NULL, name VARCHAR(120) NOT NULL, slug VARCHAR(120) NOT NULL, sort_order INT NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_shop_categories_site_id (site_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE shop_categories ADD CONSTRAINT FK_SHOP_CATEGORIES_SITE FOREIGN KEY (site_id) REFERENCES sites (id)');
        }

        if (!$schema->hasTable('shop_products')) {
            $this->addSql('CREATE TABLE shop_products (id INT AUTO_INCREMENT NOT NULL, site_id INT NOT NULL, category_id INT NOT NULL, template_id INT NOT NULL, node_id VARCHAR(64) NOT NULL, name VARCHAR(160) NOT NULL, description LONGTEXT NOT NULL, image_url VARCHAR(255) DEFAULT NULL, price_monthly_cents INT NOT NULL, cpu_limit INT NOT NULL, ram_limit INT NOT NULL, disk_limit INT NOT NULL, is_active TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_shop_products_site_id (site_id), INDEX idx_shop_products_category_id (category_id), INDEX idx_shop_products_template_id (template_id), INDEX idx_shop_products_node_id (node_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE shop_products ADD CONSTRAINT FK_SHOP_PRODUCTS_SITE FOREIGN KEY (site_id) REFERENCES sites (id)');
            $this->addSql('ALTER TABLE shop_products ADD CONSTRAINT FK_SHOP_PRODUCTS_CATEGORY FOREIGN KEY (category_id) REFERENCES shop_categories (id)');
            $this->addSql('ALTER TABLE shop_products ADD CONSTRAINT FK_SHOP_PRODUCTS_TEMPLATE FOREIGN KEY (template_id) REFERENCES game_templates (id)');
            $this->addSql('ALTER TABLE shop_products ADD CONSTRAINT FK_SHOP_PRODUCTS_NODE FOREIGN KEY (node_id) REFERENCES agents (id)');
        }

        if (!$schema->hasTable('shop_orders')) {
            $this->addSql('CREATE TABLE shop_orders (id INT AUTO_INCREMENT NOT NULL, customer_id INT NOT NULL, product_id INT NOT NULL, instance_id INT DEFAULT NULL, type VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, months INT NOT NULL, unit_price_cents INT NOT NULL, total_price_cents INT NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_shop_orders_customer_id (customer_id), INDEX idx_shop_orders_product_id (product_id), INDEX idx_shop_orders_instance_id (instance_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE shop_orders ADD CONSTRAINT FK_SHOP_ORDERS_CUSTOMER FOREIGN KEY (customer_id) REFERENCES users (id)');
            $this->addSql('ALTER TABLE shop_orders ADD CONSTRAINT FK_SHOP_ORDERS_PRODUCT FOREIGN KEY (product_id) REFERENCES shop_products (id)');
            $this->addSql('ALTER TABLE shop_orders ADD CONSTRAINT FK_SHOP_ORDERS_INSTANCE FOREIGN KEY (instance_id) REFERENCES instances (id)');
        }

        if (!$schema->hasTable('shop_rentals')) {
            $this->addSql('CREATE TABLE shop_rentals (id INT AUTO_INCREMENT NOT NULL, customer_id INT NOT NULL, product_id INT NOT NULL, instance_id INT NOT NULL, expires_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_shop_rentals_instance (instance_id), INDEX idx_shop_rentals_customer_id (customer_id), INDEX idx_shop_rentals_product_id (product_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE shop_rentals ADD CONSTRAINT FK_SHOP_RENTALS_CUSTOMER FOREIGN KEY (customer_id) REFERENCES users (id)');
            $this->addSql('ALTER TABLE shop_rentals ADD CONSTRAINT FK_SHOP_RENTALS_PRODUCT FOREIGN KEY (product_id) REFERENCES shop_products (id)');
            $this->addSql('ALTER TABLE shop_rentals ADD CONSTRAINT FK_SHOP_RENTALS_INSTANCE FOREIGN KEY (instance_id) REFERENCES instances (id)');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('shop_rentals')) {
            $this->addSql('ALTER TABLE shop_rentals DROP FOREIGN KEY FK_SHOP_RENTALS_CUSTOMER');
            $this->addSql('ALTER TABLE shop_rentals DROP FOREIGN KEY FK_SHOP_RENTALS_PRODUCT');
            $this->addSql('ALTER TABLE shop_rentals DROP FOREIGN KEY FK_SHOP_RENTALS_INSTANCE');
            $this->addSql('DROP TABLE shop_rentals');
        }

        if ($schema->hasTable('shop_orders')) {
            $this->addSql('ALTER TABLE shop_orders DROP FOREIGN KEY FK_SHOP_ORDERS_CUSTOMER');
            $this->addSql('ALTER TABLE shop_orders DROP FOREIGN KEY FK_SHOP_ORDERS_PRODUCT');
            $this->addSql('ALTER TABLE shop_orders DROP FOREIGN KEY FK_SHOP_ORDERS_INSTANCE');
            $this->addSql('DROP TABLE shop_orders');
        }

        if ($schema->hasTable('shop_products')) {
            $this->addSql('ALTER TABLE shop_products DROP FOREIGN KEY FK_SHOP_PRODUCTS_SITE');
            $this->addSql('ALTER TABLE shop_products DROP FOREIGN KEY FK_SHOP_PRODUCTS_CATEGORY');
            $this->addSql('ALTER TABLE shop_products DROP FOREIGN KEY FK_SHOP_PRODUCTS_TEMPLATE');
            $this->addSql('ALTER TABLE shop_products DROP FOREIGN KEY FK_SHOP_PRODUCTS_NODE');
            $this->addSql('DROP TABLE shop_products');
        }

        if ($schema->hasTable('shop_categories')) {
            $this->addSql('ALTER TABLE shop_categories DROP FOREIGN KEY FK_SHOP_CATEGORIES_SITE');
            $this->addSql('DROP TABLE shop_categories');
        }
    }
}


final class Version20250216100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add sites table and next_check_at for public server scheduling.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE sites (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(160) NOT NULL, host VARCHAR(160) NOT NULL, allow_private_network_targets TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX UNIQ_9EBAF22B8D7673E9 (host), INDEX idx_sites_host (host), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('INSERT INTO sites (name, host, allow_private_network_targets, created_at, updated_at) VALUES (\'Default Site\', \'localhost\', 0, NOW(), NOW())');
        $this->addSql('ALTER TABLE public_servers ADD next_check_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE public_servers DROP next_check_at');
        $this->addSql('DROP TABLE sites');
    }
}


final class Version20250217120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add status components, maintenance windows, incidents, and incident updates.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE status_components (id INT AUTO_INCREMENT NOT NULL, site_id INT NOT NULL, name VARCHAR(160) NOT NULL, type VARCHAR(40) NOT NULL, target_ref VARCHAR(255) NOT NULL, status VARCHAR(40) NOT NULL, last_checked_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', visible_public TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_status_components_site_id (site_id), INDEX idx_status_components_visibility (visible_public), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE maintenance_windows (id INT AUTO_INCREMENT NOT NULL, site_id INT NOT NULL, title VARCHAR(160) NOT NULL, message LONGTEXT DEFAULT NULL, start_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', end_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', visible_public TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_maintenance_windows_site_id (site_id), INDEX idx_maintenance_windows_visibility (visible_public), INDEX idx_maintenance_windows_start_end (start_at, end_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE maintenance_window_components (maintenance_window_id INT NOT NULL, status_component_id INT NOT NULL, INDEX IDX_9C8E50B7FB56D6 (maintenance_window_id), INDEX IDX_9C8E50CB7CB403 (status_component_id), PRIMARY KEY(maintenance_window_id, status_component_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE incidents (id INT AUTO_INCREMENT NOT NULL, site_id INT NOT NULL, title VARCHAR(160) NOT NULL, status VARCHAR(40) NOT NULL, message LONGTEXT DEFAULT NULL, started_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', resolved_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', visible_public TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_incidents_site_id (site_id), INDEX idx_incidents_visibility (visible_public), INDEX idx_incidents_status (status), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE incident_components (incident_id INT NOT NULL, status_component_id INT NOT NULL, INDEX IDX_5B0588B8D19DCC98 (incident_id), INDEX IDX_5B0588B7CB403 (status_component_id), PRIMARY KEY(incident_id, status_component_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE incident_updates (id INT AUTO_INCREMENT NOT NULL, incident_id INT NOT NULL, created_by_id INT NOT NULL, status VARCHAR(40) NOT NULL, message LONGTEXT NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_incident_updates_incident_id (incident_id), INDEX IDX_925C5D8EB03A8386 (created_by_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE maintenance_window_components ADD CONSTRAINT FK_9C8E50B7FB56D6 FOREIGN KEY (maintenance_window_id) REFERENCES maintenance_windows (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE maintenance_window_components ADD CONSTRAINT FK_9C8E50CB7CB403 FOREIGN KEY (status_component_id) REFERENCES status_components (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE incident_components ADD CONSTRAINT FK_5B0588B8D19DCC98 FOREIGN KEY (incident_id) REFERENCES incidents (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE incident_components ADD CONSTRAINT FK_5B0588B7CB403 FOREIGN KEY (status_component_id) REFERENCES status_components (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE incident_updates ADD CONSTRAINT FK_925C5D8ED19DCC98 FOREIGN KEY (incident_id) REFERENCES incidents (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE incident_updates ADD CONSTRAINT FK_925C5D8EB03A8386 FOREIGN KEY (created_by_id) REFERENCES users (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE maintenance_window_components DROP FOREIGN KEY FK_9C8E50B7FB56D6');
        $this->addSql('ALTER TABLE maintenance_window_components DROP FOREIGN KEY FK_9C8E50CB7CB403');
        $this->addSql('ALTER TABLE incident_components DROP FOREIGN KEY FK_5B0588B8D19DCC98');
        $this->addSql('ALTER TABLE incident_components DROP FOREIGN KEY FK_5B0588B7CB403');
        $this->addSql('ALTER TABLE incident_updates DROP FOREIGN KEY FK_925C5D8ED19DCC98');
        $this->addSql('ALTER TABLE incident_updates DROP FOREIGN KEY FK_925C5D8EB03A8386');
        $this->addSql('DROP TABLE incident_updates');
        $this->addSql('DROP TABLE incident_components');
        $this->addSql('DROP TABLE incidents');
        $this->addSql('DROP TABLE maintenance_window_components');
        $this->addSql('DROP TABLE maintenance_windows');
        $this->addSql('DROP TABLE status_components');
    }
}


final class Version20250218090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add download items for public downloads page.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE download_items (id INT AUTO_INCREMENT NOT NULL, site_id INT NOT NULL, title VARCHAR(160) NOT NULL, description LONGTEXT DEFAULT NULL, url VARCHAR(255) NOT NULL, version VARCHAR(80) DEFAULT NULL, file_size VARCHAR(80) DEFAULT NULL, sort_order INT NOT NULL, visible_public TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_download_items_site_id (site_id), INDEX idx_download_items_visibility (visible_public), INDEX idx_download_items_sort (sort_order), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE download_items');
    }
}


final class Version20250218093000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add changelog entries and knowledge base articles.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE changelog_entries (id INT AUTO_INCREMENT NOT NULL, site_id INT NOT NULL, title VARCHAR(160) NOT NULL, version VARCHAR(80) DEFAULT NULL, content LONGTEXT NOT NULL, published_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', visible_public TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_changelog_entries_site_id (site_id), INDEX idx_changelog_entries_visibility (visible_public), INDEX idx_changelog_entries_published (published_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE knowledge_base_articles (id INT AUTO_INCREMENT NOT NULL, site_id INT NOT NULL, title VARCHAR(160) NOT NULL, slug VARCHAR(160) NOT NULL, content LONGTEXT NOT NULL, category VARCHAR(255) NOT NULL, visible_public TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_knowledge_base_site_id (site_id), INDEX idx_knowledge_base_visibility (visible_public), INDEX idx_knowledge_base_category (category), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE knowledge_base_articles');
        $this->addSql('DROP TABLE changelog_entries');
    }
}


final class Version20250218110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add consent logs.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE consent_logs (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, type VARCHAR(255) NOT NULL, accepted_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', ip VARCHAR(64) NOT NULL, user_agent VARCHAR(255) NOT NULL, version VARCHAR(120) NOT NULL, INDEX IDX_4BB08C0A76ED395 (user_id), INDEX idx_consent_logs_type (type), INDEX idx_consent_logs_accepted (accepted_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE consent_logs ADD CONSTRAINT FK_4BB08C0A76ED395 FOREIGN KEY (user_id) REFERENCES users (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE consent_logs DROP FOREIGN KEY FK_4BB08C0A76ED395');
        $this->addSql('DROP TABLE consent_logs');
    }
}

final class Version20250315100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add query cache fields for instances.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE instances ADD query_status_cache JSON NOT NULL DEFAULT (JSON_OBJECT()), ADD query_checked_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE instances DROP query_status_cache, DROP query_checked_at');
    }
}


final class Version20250308090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add gameserver profiles, slot enforcement, and port allocations.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE port_pools ADD tag VARCHAR(120) NOT NULL');
        $this->addSql('ALTER TABLE port_pools ADD enabled TINYINT(1) NOT NULL DEFAULT 1');
        $this->addSql('UPDATE port_pools SET tag = name WHERE tag = \'\' OR tag IS NULL');

        $this->addSql('ALTER TABLE instances ADD max_slots INT NOT NULL DEFAULT 16');
        $this->addSql('ALTER TABLE instances ADD current_slots INT NOT NULL DEFAULT 16');
        $this->addSql('ALTER TABLE instances ADD lock_slots TINYINT(1) NOT NULL DEFAULT 0');
        $this->addSql('UPDATE instances SET max_slots = slots, current_slots = slots WHERE max_slots = 16 AND current_slots = 16');

        $this->addSql('CREATE TABLE game_profiles (id INT AUTO_INCREMENT NOT NULL, game_key VARCHAR(120) NOT NULL, enforce_mode_ports VARCHAR(40) NOT NULL, enforce_mode_slots VARCHAR(40) NOT NULL, port_roles JSON NOT NULL, slot_rules JSON NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_game_profiles_key (game_key), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE port_allocations (id INT AUTO_INCREMENT NOT NULL, instance_id INT NOT NULL, node_id VARCHAR(64) NOT NULL, role_key VARCHAR(80) NOT NULL, proto VARCHAR(6) NOT NULL, port INT NOT NULL, pool_tag VARCHAR(120) DEFAULT NULL, purpose VARCHAR(120) DEFAULT NULL, allocation_strategy VARCHAR(40) NOT NULL, required TINYINT(1) NOT NULL, derived_from_role_key VARCHAR(80) DEFAULT NULL, derived_offset INT DEFAULT NULL, last_checked_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', last_host_free TINYINT(1) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_port_allocations_instance (instance_id), INDEX idx_port_allocations_node (node_id), UNIQUE INDEX uniq_node_proto_port (node_id, proto, port), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE port_allocations ADD CONSTRAINT fk_port_allocations_instance FOREIGN KEY (instance_id) REFERENCES instances (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE port_allocations ADD CONSTRAINT fk_port_allocations_node FOREIGN KEY (node_id) REFERENCES agents (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE port_allocations DROP FOREIGN KEY fk_port_allocations_instance');
        $this->addSql('ALTER TABLE port_allocations DROP FOREIGN KEY fk_port_allocations_node');
        $this->addSql('DROP TABLE port_allocations');
        $this->addSql('DROP TABLE game_profiles');
        $this->addSql('ALTER TABLE instances DROP max_slots');
        $this->addSql('ALTER TABLE instances DROP current_slots');
        $this->addSql('ALTER TABLE instances DROP lock_slots');
        $this->addSql('ALTER TABLE port_pools DROP tag');
        $this->addSql('ALTER TABLE port_pools DROP enabled');
    }
}


final class Version20250218120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add customer profiles and invoice preferences.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE customer_profiles (id INT AUTO_INCREMENT NOT NULL, customer_id INT NOT NULL, first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL, address VARCHAR(255) NOT NULL, postal VARCHAR(40) NOT NULL, city VARCHAR(120) NOT NULL, country VARCHAR(2) NOT NULL, phone VARCHAR(40) DEFAULT NULL, company VARCHAR(160) DEFAULT NULL, vat_id VARCHAR(40) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_customer_profiles_customer (customer_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE invoice_preferences (id INT AUTO_INCREMENT NOT NULL, customer_id INT NOT NULL, locale VARCHAR(20) NOT NULL, email_delivery TINYINT(1) NOT NULL, pdf_download_history TINYINT(1) NOT NULL, default_payment_method VARCHAR(60) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_invoice_preferences_customer (customer_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE customer_profiles ADD CONSTRAINT FK_8E72A27C9395C3F3 FOREIGN KEY (customer_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE invoice_preferences ADD CONSTRAINT FK_2B7D5B009395C3F3 FOREIGN KEY (customer_id) REFERENCES users (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customer_profiles DROP FOREIGN KEY FK_8E72A27C9395C3F3');
        $this->addSql('ALTER TABLE invoice_preferences DROP FOREIGN KEY FK_2B7D5B009395C3F3');
        $this->addSql('DROP TABLE invoice_preferences');
        $this->addSql('DROP TABLE customer_profiles');
    }
}


final class Version20250219120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add portal language preference to invoice preferences.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE invoice_preferences ADD portal_language VARCHAR(5) NOT NULL DEFAULT 'de'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice_preferences DROP portal_language');
    }
}


final class Version20250219140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add plugin catalog entries per game template.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('game_templates')) {
            $this->addSql('CREATE TABLE game_templates (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, description LONGTEXT DEFAULT NULL, required_ports JSON NOT NULL, start_params LONGTEXT NOT NULL, install_command LONGTEXT NOT NULL, update_command LONGTEXT NOT NULL, allowed_switch_flags JSON NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }

        $this->addSql('CREATE TABLE game_template_plugins (id INT AUTO_INCREMENT NOT NULL, template_id INT NOT NULL, name VARCHAR(160) NOT NULL, version VARCHAR(80) NOT NULL, checksum VARCHAR(128) NOT NULL, download_url VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_game_template_plugins_template (template_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE game_template_plugins ADD CONSTRAINT FK_93368BF95DAF0FB7 FOREIGN KEY (template_id) REFERENCES game_templates (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_template_plugins DROP FOREIGN KEY FK_93368BF95DAF0FB7');
        $this->addSql('DROP TABLE game_template_plugins');
    }
}


final class Version20250220100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add credit notes linked to invoices.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('invoices')) {
            $this->addSql('CREATE TABLE invoices (id INT AUTO_INCREMENT NOT NULL, customer_id INT NOT NULL, number VARCHAR(40) NOT NULL, status VARCHAR(20) NOT NULL, currency VARCHAR(3) NOT NULL, amount_total_cents INT NOT NULL, amount_due_cents INT NOT NULL, due_date DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', paid_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_invoices_customer_id (customer_id), INDEX idx_invoices_status (status), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE invoices ADD CONSTRAINT FK_INVOICES_CUSTOMER FOREIGN KEY (customer_id) REFERENCES users (id)');
        }

        $this->addSql('CREATE TABLE credit_notes (id INT AUTO_INCREMENT NOT NULL, invoice_id INT NOT NULL, number VARCHAR(40) NOT NULL, status VARCHAR(20) NOT NULL, currency VARCHAR(3) NOT NULL, amount_cents INT NOT NULL, reason VARCHAR(255) DEFAULT NULL, issued_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_credit_notes_invoice_id (invoice_id), INDEX idx_credit_notes_status (status), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE credit_notes ADD CONSTRAINT FK_CREDIT_NOTES_INVOICE FOREIGN KEY (invoice_id) REFERENCES invoices (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE credit_notes DROP FOREIGN KEY FK_CREDIT_NOTES_INVOICE');
        $this->addSql('DROP TABLE credit_notes');
    }
}


final class Version20250220120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add GDPR export, deletion request, and retention policy tables.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE gdpr_exports (id INT AUTO_INCREMENT NOT NULL, customer_id INT NOT NULL, status VARCHAR(255) NOT NULL, file_name VARCHAR(160) NOT NULL, file_size INT NOT NULL, encrypted_payload JSON NOT NULL, requested_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', ready_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', expires_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_gdpr_exports_customer (customer_id), INDEX idx_gdpr_exports_status (status), INDEX idx_gdpr_exports_expires (expires_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE gdpr_exports ADD CONSTRAINT FK_5AFA2E359395C3F3 FOREIGN KEY (customer_id) REFERENCES users (id)');
        $this->addSql('CREATE TABLE gdpr_deletion_requests (id INT AUTO_INCREMENT NOT NULL, customer_id INT NOT NULL, status VARCHAR(255) NOT NULL, job_id VARCHAR(32) DEFAULT NULL, requested_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', processed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_gdpr_deletion_customer (customer_id), INDEX idx_gdpr_deletion_status (status), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE gdpr_deletion_requests ADD CONSTRAINT FK_8E540AD99395C3F3 FOREIGN KEY (customer_id) REFERENCES users (id)');
        $this->addSql('CREATE TABLE retention_policies (id INT AUTO_INCREMENT NOT NULL, ticket_retention_days INT NOT NULL, log_retention_days INT NOT NULL, session_retention_days INT NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE gdpr_exports DROP FOREIGN KEY FK_5AFA2E359395C3F3');
        $this->addSql('ALTER TABLE gdpr_deletion_requests DROP FOREIGN KEY FK_8E540AD99395C3F3');
        $this->addSql('DROP TABLE gdpr_exports');
        $this->addSql('DROP TABLE gdpr_deletion_requests');
        $this->addSql('DROP TABLE retention_policies');
    }
}


final class Version20250221100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Finalize game template schema and seed initial templates.';
    }

    public function up(Schema $schema): void
    {
        if ($this->isSqlite()) {
            $this->addSql('ALTER TABLE game_templates RENAME COLUMN name TO display_name');
            $this->addSql('ALTER TABLE game_templates ADD COLUMN game_key VARCHAR(80) DEFAULT NULL');
            $this->addSql('ALTER TABLE game_templates ADD COLUMN steam_app_id INT DEFAULT NULL');
            $this->addSql('ALTER TABLE game_templates ADD COLUMN sniper_profile VARCHAR(120) DEFAULT NULL');
            $this->addSql('ALTER TABLE game_templates ADD COLUMN env_vars JSON DEFAULT NULL');
            $this->addSql('ALTER TABLE game_templates ADD COLUMN config_files JSON DEFAULT NULL');
            $this->addSql('ALTER TABLE game_templates ADD COLUMN plugin_paths JSON DEFAULT NULL');
            $this->addSql('ALTER TABLE game_templates ADD COLUMN fastdl_settings JSON DEFAULT NULL');
        } else {
            $this->addSql('ALTER TABLE game_templates CHANGE name display_name VARCHAR(120) NOT NULL');
            $this->addSql('ALTER TABLE game_templates ADD game_key VARCHAR(80) DEFAULT NULL, ADD steam_app_id INT DEFAULT NULL, ADD sniper_profile VARCHAR(120) DEFAULT NULL, ADD env_vars JSON DEFAULT NULL, ADD config_files JSON DEFAULT NULL, ADD plugin_paths JSON DEFAULT NULL, ADD fastdl_settings JSON DEFAULT NULL');
        }

        $this->addSql(sprintf(
            "UPDATE game_templates SET game_key = %s WHERE game_key IS NULL",
            $this->isSqlite() ? "'legacy-' || id" : "CONCAT('legacy-', id)"
        ));
        $this->addSql("UPDATE game_templates SET env_vars = '[]' WHERE env_vars IS NULL");
        $this->addSql("UPDATE game_templates SET config_files = '[]' WHERE config_files IS NULL");
        $this->addSql("UPDATE game_templates SET plugin_paths = '[]' WHERE plugin_paths IS NULL");
        $this->addSql("UPDATE game_templates SET fastdl_settings = '{}' WHERE fastdl_settings IS NULL");

        if (!$this->isSqlite()) {
            $this->addSql('ALTER TABLE game_templates MODIFY game_key VARCHAR(80) NOT NULL');
            $this->addSql('ALTER TABLE game_templates MODIFY env_vars JSON NOT NULL');
            $this->addSql('ALTER TABLE game_templates MODIFY config_files JSON NOT NULL');
            $this->addSql('ALTER TABLE game_templates MODIFY plugin_paths JSON NOT NULL');
            $this->addSql('ALTER TABLE game_templates MODIFY fastdl_settings JSON NOT NULL');
        }
        // Template seeds moved to GameTemplateSeeder.
    }

    public function down(Schema $schema): void
    {
        if ($this->isSqlite()) {
            $this->addSql('ALTER TABLE game_templates DROP COLUMN game_key');
            $this->addSql('ALTER TABLE game_templates DROP COLUMN steam_app_id');
            $this->addSql('ALTER TABLE game_templates DROP COLUMN sniper_profile');
            $this->addSql('ALTER TABLE game_templates DROP COLUMN env_vars');
            $this->addSql('ALTER TABLE game_templates DROP COLUMN config_files');
            $this->addSql('ALTER TABLE game_templates DROP COLUMN plugin_paths');
            $this->addSql('ALTER TABLE game_templates DROP COLUMN fastdl_settings');
            $this->addSql('ALTER TABLE game_templates RENAME COLUMN display_name TO name');
        } else {
            $this->addSql('ALTER TABLE game_templates DROP game_key, DROP steam_app_id, DROP sniper_profile, DROP env_vars, DROP config_files, DROP plugin_paths, DROP fastdl_settings');
            $this->addSql('ALTER TABLE game_templates CHANGE display_name name VARCHAR(120) NOT NULL');
        }
    }

    private function isSqlite(): bool
    {
        return $this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\SQLitePlatform;
    }
}


final class Version20250301090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add instance update policy and build metadata tracking.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('agents')) {
            $this->addSql('CREATE TABLE agents (id VARCHAR(64) NOT NULL, name VARCHAR(120) DEFAULT NULL, secret_payload JSON NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', last_heartbeat_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', last_seen_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', last_heartbeat_ip VARCHAR(45) DEFAULT NULL, last_heartbeat_version VARCHAR(40) DEFAULT NULL, last_heartbeat_stats JSON DEFAULT NULL, metadata JSON DEFAULT NULL, roles JSON NOT NULL, status VARCHAR(20) NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }

        if (!$schema->hasTable('instances')) {
            $this->addSql('CREATE TABLE instances (id INT AUTO_INCREMENT NOT NULL, customer_id INT NOT NULL, template_id INT NOT NULL, node_id VARCHAR(64) NOT NULL, cpu_limit INT NOT NULL, ram_limit INT NOT NULL, disk_limit INT NOT NULL, port_block_id VARCHAR(64) DEFAULT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_instances_customer_id (customer_id), INDEX idx_instances_template_id (template_id), INDEX idx_instances_node_id (node_id), INDEX idx_instances_status (status), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE instances ADD CONSTRAINT FK_INSTANCES_CUSTOMER FOREIGN KEY (customer_id) REFERENCES users (id)');
            $this->addSql('ALTER TABLE instances ADD CONSTRAINT FK_INSTANCES_TEMPLATE FOREIGN KEY (template_id) REFERENCES game_templates (id)');
            $this->addSql('ALTER TABLE instances ADD CONSTRAINT FK_INSTANCES_NODE FOREIGN KEY (node_id) REFERENCES agents (id)');
        }

        $this->addSql('ALTER TABLE instances ADD update_policy VARCHAR(16) NOT NULL DEFAULT \'manual\', ADD locked_build_id VARCHAR(64) DEFAULT NULL, ADD locked_version VARCHAR(120) DEFAULT NULL, ADD current_build_id VARCHAR(64) DEFAULT NULL, ADD current_version VARCHAR(120) DEFAULT NULL, ADD previous_build_id VARCHAR(64) DEFAULT NULL, ADD previous_version VARCHAR(120) DEFAULT NULL, ADD last_update_queued_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE instances DROP update_policy, DROP locked_build_id, DROP locked_version, DROP current_build_id, DROP current_version, DROP previous_build_id, DROP previous_version, DROP last_update_queued_at');
    }
}


final class Version20250302120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add notifications table for panel alerts.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE notifications (id INT AUTO_INCREMENT NOT NULL, recipient_id INT NOT NULL, category VARCHAR(32) NOT NULL, title VARCHAR(160) NOT NULL, body VARCHAR(255) NOT NULL, action_url VARCHAR(255) DEFAULT NULL, event_key VARCHAR(120) NOT NULL, read_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX notifications_recipient_created_idx (recipient_id, created_at), UNIQUE INDEX notifications_recipient_event_key_idx (recipient_id, event_key), INDEX IDX_6000B0D3E92F8F78 (recipient_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE notifications ADD CONSTRAINT FK_6000B0D3E92F8F78 FOREIGN KEY (recipient_id) REFERENCES users (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notifications DROP FOREIGN KEY FK_6000B0D3E92F8F78');
        $this->addSql('DROP TABLE notifications');
    }
}


final class Version20250305090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add CMS pages scoped to sites and seed legal templates.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE cms_pages (id INT AUTO_INCREMENT NOT NULL, site_id INT NOT NULL, title VARCHAR(160) NOT NULL, slug VARCHAR(160) NOT NULL, is_published TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_cms_pages_site_id (site_id), UNIQUE INDEX uniq_cms_pages_site_slug (site_id, slug), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE cms_blocks (id INT AUTO_INCREMENT NOT NULL, page_id INT NOT NULL, type VARCHAR(80) NOT NULL, content LONGTEXT NOT NULL, sort_order INT NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_cms_blocks_page_id (page_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE cms_pages ADD CONSTRAINT FK_CMS_PAGES_SITE FOREIGN KEY (site_id) REFERENCES sites (id)');
        $this->addSql('ALTER TABLE cms_blocks ADD CONSTRAINT FK_CMS_BLOCKS_PAGE FOREIGN KEY (page_id) REFERENCES cms_pages (id)');

        $this->addSql(<<<'SQL'
INSERT INTO cms_pages (site_id, title, slug, is_published, created_at, updated_at)
SELECT id, 'Impressum', 'impressum', 1, NOW(), NOW() FROM sites
SQL);
        $this->addSql(<<<'SQL'
INSERT INTO cms_pages (site_id, title, slug, is_published, created_at, updated_at)
SELECT id, 'Datenschutz', 'datenschutz', 1, NOW(), NOW() FROM sites
SQL);
        $this->addSql(<<<'SQL'
INSERT INTO cms_pages (site_id, title, slug, is_published, created_at, updated_at)
SELECT id, 'AGB', 'agb', 1, NOW(), NOW() FROM sites
SQL);

        $this->addSql(<<<'SQL'
INSERT INTO cms_blocks (page_id, type, content, sort_order, created_at, updated_at)
SELECT id, 'text', '<h2>Angaben gemäß § 5 TMG</h2><p>[Firmenname]</p><p>[Straße Hausnummer]</p><p>[PLZ Ort]</p><p>Vertreten durch: [Geschäftsführer]</p><p>Kontakt: [Telefon] · [E-Mail]</p><p>USt-IdNr.: [USt-ID]</p>', 1, NOW(), NOW()
FROM cms_pages WHERE slug = 'impressum'
SQL);
        $this->addSql(<<<'SQL'
INSERT INTO cms_blocks (page_id, type, content, sort_order, created_at, updated_at)
SELECT id, 'text', '<h2>Datenschutzerklärung</h2><p>Wir verarbeiten personenbezogene Daten gemäß DSGVO. Bitte ergänzen Sie die verantwortliche Stelle, Kontaktwege und Zwecke der Verarbeitung.</p><h3>Verantwortliche Stelle</h3><p>[Firmenname] · [Adresse] · [Kontakt]</p><h3>Betroffenenrechte</h3><p>Sie haben das Recht auf Auskunft, Berichtigung, Löschung, Einschränkung und Datenübertragbarkeit.</p><h3>Speicherdauer</h3><p>[Speicherdauer / Kriterien]</p>', 1, NOW(), NOW()
FROM cms_pages WHERE slug = 'datenschutz'
SQL);
        $this->addSql(<<<'SQL'
INSERT INTO cms_blocks (page_id, type, content, sort_order, created_at, updated_at)
SELECT id, 'text', '<h2>Allgemeine Geschäftsbedingungen</h2><p>Diese AGB gelten für alle Leistungen von [Firmenname]. Bitte ergänzen Sie Leistungsumfang, Zahlungsbedingungen und Kündigungsfristen.</p><h3>Leistungen</h3><p>[Beschreibung der Leistungen]</p><h3>Zahlung</h3><p>[Zahlungsbedingungen]</p><h3>Laufzeit & Kündigung</h3><p>[Laufzeit und Kündigungsregeln]</p>', 1, NOW(), NOW()
FROM cms_pages WHERE slug = 'agb'
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cms_blocks DROP FOREIGN KEY FK_CMS_BLOCKS_PAGE');
        $this->addSql('ALTER TABLE cms_pages DROP FOREIGN KEY FK_CMS_PAGES_SITE');
        $this->addSql('DROP TABLE cms_blocks');
        $this->addSql('DROP TABLE cms_pages');
    }
}


final class Version20250305093000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add immutable invoice archive storage.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE invoice_archives (id INT AUTO_INCREMENT NOT NULL, invoice_id INT NOT NULL, file_name VARCHAR(160) NOT NULL, content_type VARCHAR(80) NOT NULL, file_size INT NOT NULL, pdf_hash VARCHAR(64) NOT NULL, pdf_data LONGBLOB NOT NULL, archived_year INT NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_invoice_archives_invoice (invoice_id), INDEX idx_invoice_archives_year (archived_year), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE invoice_archives ADD CONSTRAINT FK_INVOICE_ARCHIVES_INVOICE FOREIGN KEY (invoice_id) REFERENCES invoices (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice_archives DROP FOREIGN KEY FK_INVOICE_ARCHIVES_INVOICE');
        $this->addSql('DROP TABLE invoice_archives');
    }
}


final class Version20250305120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add CMS template selection to sites.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sites ADD cms_template_key VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sites DROP cms_template_key');
    }
}


final class Version20250310120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed additional game templates and popular plugins.';
    }

    public function up(Schema $schema): void
    {
        return;
        $this->insertTemplate(
            'palworld',
            'Palworld Dedicated Server',
            'SteamCMD install with default Palworld settings.',
            2394010,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
            ],
            '{{INSTANCE_DIR}}/PalServer.sh -useperfthreads -NoAsyncLoadingThread -UseMultithreadForDS -port {{PORT_GAME}} -queryport {{PORT_QUERY}} -servername "{{SERVER_NAME}}" -serverpassword "{{SERVER_PASSWORD}}"',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi Palworld'],
                ['key' => 'SERVER_PASSWORD', 'value' => 'change-me'],
            ],
            [
                [
                    'path' => 'Pal/Saved/Config/LinuxServer/PalWorldSettings.ini',
                    'description' => 'Palworld server settings',
                ],
            ],
            [],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 2394010 validate +quit',
            'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 2394010 +quit',
            []
        );

        $this->insertTemplate(
            'palworld_windows',
            'Palworld Dedicated Server (Windows)',
            'SteamCMD install with Windows server binary.',
            2394010,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
            ],
            '{{INSTANCE_DIR}}/PalServer.exe -port {{PORT_GAME}} -queryport {{PORT_QUERY}} -servername "{{SERVER_NAME}}" -serverpassword "{{SERVER_PASSWORD}}"',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi Palworld'],
                ['key' => 'SERVER_PASSWORD', 'value' => 'change-me'],
            ],
            [
                [
                    'path' => 'Pal/Saved/Config/WindowsServer/PalWorldSettings.ini',
                    'description' => 'Palworld server settings',
                ],
            ],
            [],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 2394010 validate +quit',
            'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 2394010 +quit',
            []
        );

        $this->insertTemplate(
            'valheim_windows',
            'Valheim Dedicated Server (Windows)',
            'SteamCMD install with Windows server executable.',
            896660,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
            ],
            '{{INSTANCE_DIR}}/valheim_server.exe -name "{{SERVER_NAME}}" -port {{PORT_GAME}} -world "{{WORLD_NAME}}" -password "{{SERVER_PASSWORD}}" -public 1',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi Valheim'],
                ['key' => 'WORLD_NAME', 'value' => 'Dedicated'],
                ['key' => 'SERVER_PASSWORD', 'value' => 'change-me'],
            ],
            [],
            [],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 896660 validate +quit',
            'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 896660 +quit',
            []
        );

        $this->insertTemplate(
            'satisfactory_windows',
            'Satisfactory Dedicated Server (Windows)',
            'SteamCMD install with Windows server executable.',
            1690800,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
            ],
            '{{INSTANCE_DIR}}/FactoryServer.exe -log -unattended',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi Satisfactory'],
            ],
            [
                [
                    'path' => 'FactoryGame/Saved/Config/WindowsServer/ServerSettings.ini',
                    'description' => 'Server settings',
                ],
            ],
            [],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 1690800 validate +quit',
            'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 1690800 +quit',
            []
        );

        $this->insertTemplate(
            'dayz',
            'DayZ Dedicated Server',
            'SteamCMD install with basic serverDZ.cfg.',
            223350,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
            ],
            '{{INSTANCE_DIR}}/DayZServer_x64 -config=serverDZ.cfg -port={{PORT_GAME}}',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi DayZ'],
            ],
            [
                [
                    'path' => 'serverDZ.cfg',
                    'description' => 'Server configuration',
                ],
            ],
            [
                'mods',
            ],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 223350 validate +quit',
            'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 223350 +quit',
            []
        );

        $this->insertTemplate(
            'dayz_windows',
            'DayZ Dedicated Server (Windows)',
            'SteamCMD install with Windows server binary.',
            223350,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
            ],
            '{{INSTANCE_DIR}}/DayZServer_x64.exe -config=serverDZ.cfg -port={{PORT_GAME}}',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi DayZ'],
            ],
            [
                [
                    'path' => 'serverDZ.cfg',
                    'description' => 'Server configuration',
                ],
            ],
            [
                'mods',
            ],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 223350 validate +quit',
            'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 223350 +quit',
            []
        );

        $this->insertTemplate(
            'v_rising',
            'V Rising Dedicated Server',
            'SteamCMD install with standard server config.',
            1829350,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
            ],
            '{{INSTANCE_DIR}}/VRisingServer -persistentDataPath ./save-data -serverName "{{SERVER_NAME}}"',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi V Rising'],
            ],
            [
                [
                    'path' => 'save-data/Settings/ServerHostSettings.json',
                    'description' => 'Server host settings',
                ],
            ],
            [],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 1829350 validate +quit',
            'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 1829350 +quit',
            []
        );

        $this->insertTemplate(
            'v_rising_windows',
            'V Rising Dedicated Server (Windows)',
            'SteamCMD install with Windows server binary.',
            1829350,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
            ],
            '{{INSTANCE_DIR}}/VRisingServer.exe -persistentDataPath .\\save-data -serverName "{{SERVER_NAME}}"',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi V Rising'],
            ],
            [
                [
                    'path' => 'save-data/Settings/ServerHostSettings.json',
                    'description' => 'Server host settings',
                ],
            ],
            [],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 1829350 validate +quit',
            'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 1829350 +quit',
            []
        );

        $this->insertTemplate(
            'enshrouded_windows',
            'Enshrouded Dedicated Server (Windows)',
            'SteamCMD install with Windows server binary.',
            2278520,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
            ],
            '{{INSTANCE_DIR}}/enshrouded_server.exe -log',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi Enshrouded'],
            ],
            [
                [
                    'path' => 'enshrouded_server.json',
                    'description' => 'Server configuration',
                ],
            ],
            [],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 2278520 validate +quit',
            'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 2278520 +quit',
            []
        );

        $this->insertTemplate(
            'garrys_mod',
            "Garry's Mod Dedicated Server",
            'SteamCMD install with standard Source DS layout.',
            4020,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                ['name' => 'tv', 'label' => 'SourceTV', 'protocol' => 'udp'],
            ],
            '{{INSTANCE_DIR}}/srcds_run -game garrysmod -console +map gm_construct +maxplayers {{MAX_PLAYERS}} +hostname "{{SERVER_NAME}}"',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi Garry\'s Mod'],
                ['key' => 'MAX_PLAYERS', 'value' => '24'],
            ],
            [
                [
                    'path' => 'garrysmod/cfg/server.cfg',
                    'description' => 'Base server configuration',
                    'contents' => "hostname \"{{SERVER_NAME}}\"\nsv_lan 0\n",
                ],
            ],
            [
                'garrysmod/addons',
            ],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 4020 validate +quit',
            'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 4020 +quit',
            ['+map', '+maxplayers']
        );

        $this->insertTemplate(
            'terraria',
            'Terraria Dedicated Server',
            'SteamCMD install with config-based server setup.',
            105600,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
            ],
            '{{INSTANCE_DIR}}/TerrariaServer.bin.x86_64 -config serverconfig.txt',
            [
                ['key' => 'WORLD_NAME', 'value' => 'EasyWiWorld'],
                ['key' => 'MAX_PLAYERS', 'value' => '16'],
                ['key' => 'SERVER_PASSWORD', 'value' => ''],
            ],
            [
                [
                    'path' => 'serverconfig.txt',
                    'description' => 'Terraria server configuration',
                    'contents' => "world=worlds/{{WORLD_NAME}}.wld\nmaxplayers={{MAX_PLAYERS}}\npassword={{SERVER_PASSWORD}}\n",
                ],
            ],
            [],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 105600 validate +quit',
            'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 105600 +quit',
            []
        );

        $this->insertTemplate(
            'seven_days_to_die',
            '7 Days to Die Dedicated Server',
            'SteamCMD install with XML-based configuration.',
            294420,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                ['name' => 'rcon', 'label' => 'RCON', 'protocol' => 'tcp'],
            ],
            '{{INSTANCE_DIR}}/startserver.sh -configfile=serverconfig.xml -quit -batchmode -nographics -dedicated',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi 7DTD'],
                ['key' => 'MAX_PLAYERS', 'value' => '12'],
            ],
            [
                [
                    'path' => 'serverconfig.xml',
                    'description' => 'Server configuration',
                ],
            ],
            [
                'Mods',
            ],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 294420 validate +quit',
            'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 294420 +quit',
            []
        );

        $this->insertTemplate(
            'factorio',
            'Factorio Dedicated Server',
            'SteamCMD install with JSON server settings.',
            427520,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
            ],
            '{{INSTANCE_DIR}}/bin/x64/factorio --start-server save.zip --server-settings server-settings.json',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi Factorio'],
            ],
            [
                [
                    'path' => 'server-settings.json',
                    'description' => 'Server settings',
                    'contents' => "{\n  \"name\": \"{{SERVER_NAME}}\",\n  \"description\": \"Easy-Wi Factorio Server\",\n  \"max_players\": 20\n}\n",
                ],
            ],
            [],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 427520 validate +quit',
            'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 427520 +quit',
            []
        );

        $this->insertTemplate(
            'project_zomboid',
            'Project Zomboid Dedicated Server',
            'SteamCMD install with standard Linux server scripts.',
            380870,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
            ],
            '{{INSTANCE_DIR}}/start-server.sh -servername "{{SERVER_NAME}}" -adminpassword "{{ADMIN_PASSWORD}}"',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi Zomboid'],
                ['key' => 'ADMIN_PASSWORD', 'value' => 'change-me'],
            ],
            [
                [
                    'path' => 'Zomboid/Server/server.ini',
                    'description' => 'Server configuration',
                ],
            ],
            [
                'Zomboid/mods',
            ],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 380870 validate +quit',
            'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 380870 +quit',
            []
        );

        $this->insertTemplate(
            'project_zomboid_windows',
            'Project Zomboid Dedicated Server (Windows)',
            'SteamCMD install with Windows server scripts.',
            380870,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
            ],
            '{{INSTANCE_DIR}}/StartServer64.bat -servername "{{SERVER_NAME}}" -adminpassword "{{ADMIN_PASSWORD}}"',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi Zomboid'],
                ['key' => 'ADMIN_PASSWORD', 'value' => 'change-me'],
            ],
            [
                [
                    'path' => 'Zomboid/Server/server.ini',
                    'description' => 'Server configuration',
                ],
            ],
            [
                'Zomboid/mods',
            ],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 380870 validate +quit',
            'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 380870 +quit',
            []
        );

        $this->insertTemplate(
            'conan_exiles',
            'Conan Exiles Dedicated Server',
            'SteamCMD install with default Linux config paths.',
            443030,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
            ],
            '{{INSTANCE_DIR}}/ConanSandboxServer -log -Port={{PORT_GAME}} -QueryPort={{PORT_QUERY}}',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi Conan Exiles'],
            ],
            [
                [
                    'path' => 'ConanSandbox/Saved/Config/LinuxServer/ServerSettings.ini',
                    'description' => 'Server settings',
                ],
            ],
            [
                'ConanSandbox/Mods',
            ],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 443030 validate +quit',
            'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 443030 +quit',
            []
        );

        $this->insertTemplate(
            'conan_exiles_windows',
            'Conan Exiles Dedicated Server (Windows)',
            'SteamCMD install with Windows config paths.',
            443030,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
            ],
            '{{INSTANCE_DIR}}/ConanSandboxServer.exe -log -Port={{PORT_GAME}} -QueryPort={{PORT_QUERY}}',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi Conan Exiles'],
            ],
            [
                [
                    'path' => 'ConanSandbox/Saved/Config/WindowsServer/ServerSettings.ini',
                    'description' => 'Server settings',
                ],
            ],
            [
                'ConanSandbox/Mods',
            ],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 443030 validate +quit',
            'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 443030 +quit',
            []
        );

        $this->insertTemplate(
            'arma3',
            'Arma 3 Dedicated Server',
            'SteamCMD install with standard Linux server config.',
            233780,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
            ],
            '{{INSTANCE_DIR}}/arma3server_x64 -config=server.cfg -port={{PORT_GAME}} -name=server',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi Arma 3'],
            ],
            [
                [
                    'path' => 'server.cfg',
                    'description' => 'Server configuration',
                    'contents' => "hostname=\"{{SERVER_NAME}}\"\nmaxPlayers=40;\n",
                ],
            ],
            [
                'mods',
            ],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 233780 validate +quit',
            'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 233780 +quit',
            []
        );

        $this->insertTemplate(
            'arma3_windows',
            'Arma 3 Dedicated Server (Windows)',
            'SteamCMD install with Windows server config.',
            233780,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
            ],
            '{{INSTANCE_DIR}}/arma3server_x64.exe -config=server.cfg -port={{PORT_GAME}} -name=server',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi Arma 3'],
            ],
            [
                [
                    'path' => 'server.cfg',
                    'description' => 'Server configuration',
                    'contents' => "hostname=\"{{SERVER_NAME}}\"\nmaxPlayers=40;\n",
                ],
            ],
            [
                'mods',
            ],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 233780 validate +quit',
            'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 233780 +quit',
            []
        );

        $this->insertTemplate(
            'minecraft_java_1_19_4',
            'Minecraft Java (Paper 1.19.4)',
            'PaperMC install with EULA acceptance for 1.19.4.',
            null,
            null,
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'tcp'],
            ],
            'java -Xms{{JAVA_XMS}} -Xmx{{JAVA_XMX}} -jar {{INSTANCE_DIR}}/server.jar nogui',
            [
                ['key' => 'JAVA_XMS', 'value' => '1G'],
                ['key' => 'JAVA_XMX', 'value' => '2G'],
            ],
            [
                [
                    'path' => 'eula.txt',
                    'description' => 'Minecraft EULA acceptance',
                    'contents' => "eula=true\n",
                ],
                [
                    'path' => 'server.properties',
                    'description' => 'Base server settings',
                    'contents' => "motd=Easy-Wi Minecraft 1.19.4\nview-distance=10\n",
                ],
            ],
            [],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'curl -L -o server.jar https://api.papermc.io/v2/projects/paper/versions/1.19.4/builds/1/downloads/paper-1.19.4-1.jar',
            'curl -L -o server.jar https://api.papermc.io/v2/projects/paper/versions/1.19.4/builds/1/downloads/paper-1.19.4-1.jar',
            []
        );

        $this->insertTemplate(
            'minecraft_java_1_18_2',
            'Minecraft Java (Paper 1.18.2)',
            'PaperMC install with EULA acceptance for 1.18.2.',
            null,
            null,
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'tcp'],
            ],
            'java -Xms{{JAVA_XMS}} -Xmx{{JAVA_XMX}} -jar {{INSTANCE_DIR}}/server.jar nogui',
            [
                ['key' => 'JAVA_XMS', 'value' => '1G'],
                ['key' => 'JAVA_XMX', 'value' => '2G'],
            ],
            [
                [
                    'path' => 'eula.txt',
                    'description' => 'Minecraft EULA acceptance',
                    'contents' => "eula=true\n",
                ],
                [
                    'path' => 'server.properties',
                    'description' => 'Base server settings',
                    'contents' => "motd=Easy-Wi Minecraft 1.18.2\nview-distance=10\n",
                ],
            ],
            [],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'curl -L -o server.jar https://api.papermc.io/v2/projects/paper/versions/1.18.2/builds/1/downloads/paper-1.18.2-1.jar',
            'curl -L -o server.jar https://api.papermc.io/v2/projects/paper/versions/1.18.2/builds/1/downloads/paper-1.18.2-1.jar',
            []
        );

        $this->insertTemplate(
            'minecraft_java_windows',
            'Minecraft Java (Paper, Windows)',
            'PaperMC install with EULA acceptance on Windows.',
            null,
            null,
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'tcp'],
            ],
            'java -Xms{{JAVA_XMS}} -Xmx{{JAVA_XMX}} -jar {{INSTANCE_DIR}}/server.jar nogui',
            [
                ['key' => 'JAVA_XMS', 'value' => '1G'],
                ['key' => 'JAVA_XMX', 'value' => '2G'],
            ],
            [
                [
                    'path' => 'eula.txt',
                    'description' => 'Minecraft EULA acceptance',
                    'contents' => "eula=true\n",
                ],
                [
                    'path' => 'server.properties',
                    'description' => 'Base server settings',
                    'contents' => "motd=Easy-Wi Minecraft Windows\nview-distance=10\n",
                ],
            ],
            [],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'powershell -Command \"Invoke-WebRequest -Uri https://api.papermc.io/v2/projects/paper/versions/1.20.4/builds/1/downloads/paper-1.20.4-1.jar -OutFile server.jar\"',
            'powershell -Command \"Invoke-WebRequest -Uri https://api.papermc.io/v2/projects/paper/versions/1.20.4/builds/1/downloads/paper-1.20.4-1.jar -OutFile server.jar\"',
            []
        );

        $this->insertTemplate(
            'minecraft_java_1_19_4_windows',
            'Minecraft Java (Paper 1.19.4, Windows)',
            'PaperMC install with EULA acceptance for 1.19.4 on Windows.',
            null,
            null,
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'tcp'],
            ],
            'java -Xms{{JAVA_XMS}} -Xmx{{JAVA_XMX}} -jar {{INSTANCE_DIR}}/server.jar nogui',
            [
                ['key' => 'JAVA_XMS', 'value' => '1G'],
                ['key' => 'JAVA_XMX', 'value' => '2G'],
            ],
            [
                [
                    'path' => 'eula.txt',
                    'description' => 'Minecraft EULA acceptance',
                    'contents' => "eula=true\n",
                ],
                [
                    'path' => 'server.properties',
                    'description' => 'Base server settings',
                    'contents' => "motd=Easy-Wi Minecraft 1.19.4\nview-distance=10\n",
                ],
            ],
            [],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'powershell -Command \"Invoke-WebRequest -Uri https://api.papermc.io/v2/projects/paper/versions/1.19.4/builds/1/downloads/paper-1.19.4-1.jar -OutFile server.jar\"',
            'powershell -Command \"Invoke-WebRequest -Uri https://api.papermc.io/v2/projects/paper/versions/1.19.4/builds/1/downloads/paper-1.19.4-1.jar -OutFile server.jar\"',
            []
        );

        $this->insertTemplate(
            'minecraft_java_1_18_2_windows',
            'Minecraft Java (Paper 1.18.2, Windows)',
            'PaperMC install with EULA acceptance for 1.18.2 on Windows.',
            null,
            null,
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'tcp'],
            ],
            'java -Xms{{JAVA_XMS}} -Xmx{{JAVA_XMX}} -jar {{INSTANCE_DIR}}/server.jar nogui',
            [
                ['key' => 'JAVA_XMS', 'value' => '1G'],
                ['key' => 'JAVA_XMX', 'value' => '2G'],
            ],
            [
                [
                    'path' => 'eula.txt',
                    'description' => 'Minecraft EULA acceptance',
                    'contents' => "eula=true\n",
                ],
                [
                    'path' => 'server.properties',
                    'description' => 'Base server settings',
                    'contents' => "motd=Easy-Wi Minecraft 1.18.2\nview-distance=10\n",
                ],
            ],
            [],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'powershell -Command \"Invoke-WebRequest -Uri https://api.papermc.io/v2/projects/paper/versions/1.18.2/builds/1/downloads/paper-1.18.2-1.jar -OutFile server.jar\"',
            'powershell -Command \"Invoke-WebRequest -Uri https://api.papermc.io/v2/projects/paper/versions/1.18.2/builds/1/downloads/paper-1.18.2-1.jar -OutFile server.jar\"',
            []
        );

        $this->insertTemplate(
            'cs2_windows',
            'Counter-Strike 2 Dedicated Server (Windows)',
            'SteamCMD install with Windows server binary.',
            730,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                ['name' => 'rcon', 'label' => 'RCON', 'protocol' => 'tcp'],
                ['name' => 'tv', 'label' => 'SourceTV', 'protocol' => 'udp'],
            ],
            '{{INSTANCE_DIR}}/srcds.exe -game cs2 -console -usercon -tickrate 128 +map de_dust2 +sv_setsteamaccount {{STEAM_GSLT}} +hostname "{{SERVER_NAME}}" +rcon_password "{{RCON_PASSWORD}}"',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi CS2'],
                ['key' => 'SERVER_PASSWORD', 'value' => ''],
                ['key' => 'RCON_PASSWORD', 'value' => 'change-me'],
                ['key' => 'STEAM_GSLT', 'value' => ''],
            ],
            [
                [
                    'path' => 'game/csgo/cfg/server.cfg',
                    'description' => 'Base server configuration',
                    'contents' => "hostname \"{{SERVER_NAME}}\"\nrcon_password \"{{RCON_PASSWORD}}\"\nsv_password \"{{SERVER_PASSWORD}}\"\nsv_lan 0\n",
                ],
            ],
            [
                'game/csgo/addons/metamod',
                'game/csgo/addons/counterstrikesharp',
            ],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 730 validate +quit',
            'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 730 +quit',
            ['+map', '+maxplayers']
        );

        $this->insertTemplate(
            'csgo_legacy_windows',
            'Counter-Strike: Global Offensive (Legacy, Windows)',
            'Legacy CSGO dedicated server template for Windows.',
            740,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                ['name' => 'rcon', 'label' => 'RCON', 'protocol' => 'tcp'],
                ['name' => 'tv', 'label' => 'SourceTV', 'protocol' => 'udp'],
            ],
            '{{INSTANCE_DIR}}/srcds.exe -game csgo -console -usercon -tickrate 128 +map de_dust2 +sv_setsteamaccount {{STEAM_GSLT}} +hostname "{{SERVER_NAME}}" +rcon_password "{{RCON_PASSWORD}}"',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi CSGO'],
                ['key' => 'SERVER_PASSWORD', 'value' => ''],
                ['key' => 'RCON_PASSWORD', 'value' => 'change-me'],
                ['key' => 'STEAM_GSLT', 'value' => ''],
            ],
            [
                [
                    'path' => 'csgo/cfg/server.cfg',
                    'description' => 'Base server configuration',
                    'contents' => "hostname \"{{SERVER_NAME}}\"\nrcon_password \"{{RCON_PASSWORD}}\"\nsv_password \"{{SERVER_PASSWORD}}\"\nsv_lan 0\n",
                ],
            ],
            [
                'csgo/addons/metamod',
                'csgo/addons/sourcemod',
            ],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 740 validate +quit',
            'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 740 +quit',
            ['+map', '+maxplayers']
        );

        $this->insertTemplate(
            'tf2',
            'Team Fortress 2 Dedicated Server',
            'SteamCMD install with base server config.',
            232250,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                ['name' => 'rcon', 'label' => 'RCON', 'protocol' => 'tcp'],
            ],
            '{{INSTANCE_DIR}}/srcds_run -game tf +map ctf_2fort +maxplayers {{MAX_PLAYERS}} +hostname "{{SERVER_NAME}}" +rcon_password "{{RCON_PASSWORD}}"',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi TF2'],
                ['key' => 'MAX_PLAYERS', 'value' => '24'],
                ['key' => 'RCON_PASSWORD', 'value' => 'change-me'],
            ],
            [
                [
                    'path' => 'tf/cfg/server.cfg',
                    'description' => 'Base server configuration',
                ],
            ],
            [
                'tf/addons/metamod',
                'tf/addons/sourcemod',
            ],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 232250 validate +quit',
            'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 232250 +quit',
            ['+map', '+maxplayers']
        );

        $this->insertTemplate(
            'tf2_windows',
            'Team Fortress 2 Dedicated Server (Windows)',
            'SteamCMD install with Windows server binary.',
            232250,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                ['name' => 'rcon', 'label' => 'RCON', 'protocol' => 'tcp'],
            ],
            '{{INSTANCE_DIR}}/srcds.exe -game tf +map ctf_2fort +maxplayers {{MAX_PLAYERS}} +hostname "{{SERVER_NAME}}" +rcon_password "{{RCON_PASSWORD}}"',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi TF2'],
                ['key' => 'MAX_PLAYERS', 'value' => '24'],
                ['key' => 'RCON_PASSWORD', 'value' => 'change-me'],
            ],
            [
                [
                    'path' => 'tf/cfg/server.cfg',
                    'description' => 'Base server configuration',
                ],
            ],
            [
                'tf/addons/metamod',
                'tf/addons/sourcemod',
            ],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 232250 validate +quit',
            'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 232250 +quit',
            ['+map', '+maxplayers']
        );

        $this->insertTemplate(
            'css',
            'Counter-Strike: Source Dedicated Server',
            'SteamCMD install with base server config.',
            232330,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                ['name' => 'rcon', 'label' => 'RCON', 'protocol' => 'tcp'],
                ['name' => 'tv', 'label' => 'SourceTV', 'protocol' => 'udp'],
            ],
            '{{INSTANCE_DIR}}/srcds_run -game cstrike +map de_dust2 +maxplayers {{MAX_PLAYERS}} +hostname "{{SERVER_NAME}}" +rcon_password "{{RCON_PASSWORD}}"',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi CSS'],
                ['key' => 'MAX_PLAYERS', 'value' => '24'],
                ['key' => 'RCON_PASSWORD', 'value' => 'change-me'],
            ],
            [
                [
                    'path' => 'cstrike/cfg/server.cfg',
                    'description' => 'Base server configuration',
                ],
            ],
            [
                'cstrike/addons/metamod',
                'cstrike/addons/sourcemod',
            ],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 232330 validate +quit',
            'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 232330 +quit',
            ['+map', '+maxplayers']
        );

        $this->insertTemplate(
            'css_windows',
            'Counter-Strike: Source Dedicated Server (Windows)',
            'SteamCMD install with Windows server binary.',
            232330,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                ['name' => 'rcon', 'label' => 'RCON', 'protocol' => 'tcp'],
                ['name' => 'tv', 'label' => 'SourceTV', 'protocol' => 'udp'],
            ],
            '{{INSTANCE_DIR}}/srcds.exe -game cstrike +map de_dust2 +maxplayers {{MAX_PLAYERS}} +hostname "{{SERVER_NAME}}" +rcon_password "{{RCON_PASSWORD}}"',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi CSS'],
                ['key' => 'MAX_PLAYERS', 'value' => '24'],
                ['key' => 'RCON_PASSWORD', 'value' => 'change-me'],
            ],
            [
                [
                    'path' => 'cstrike/cfg/server.cfg',
                    'description' => 'Base server configuration',
                ],
            ],
            [
                'cstrike/addons/metamod',
                'cstrike/addons/sourcemod',
            ],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 232330 validate +quit',
            'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 232330 +quit',
            ['+map', '+maxplayers']
        );

        $this->insertTemplate(
            'hl2dm',
            'Half-Life 2: Deathmatch Dedicated Server',
            'SteamCMD install with base server config.',
            232370,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                ['name' => 'rcon', 'label' => 'RCON', 'protocol' => 'tcp'],
            ],
            '{{INSTANCE_DIR}}/srcds_run -game hl2mp +map dm_lockdown +maxplayers {{MAX_PLAYERS}} +hostname "{{SERVER_NAME}}" +rcon_password "{{RCON_PASSWORD}}"',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi HL2DM'],
                ['key' => 'MAX_PLAYERS', 'value' => '24'],
                ['key' => 'RCON_PASSWORD', 'value' => 'change-me'],
            ],
            [
                [
                    'path' => 'hl2mp/cfg/server.cfg',
                    'description' => 'Base server configuration',
                ],
            ],
            [],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 232370 validate +quit',
            'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 232370 +quit',
            ['+map', '+maxplayers']
        );

        $this->insertTemplate(
            'hl2dm_windows',
            'Half-Life 2: Deathmatch Dedicated Server (Windows)',
            'SteamCMD install with Windows server binary.',
            232370,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                ['name' => 'rcon', 'label' => 'RCON', 'protocol' => 'tcp'],
            ],
            '{{INSTANCE_DIR}}/srcds.exe -game hl2mp +map dm_lockdown +maxplayers {{MAX_PLAYERS}} +hostname "{{SERVER_NAME}}" +rcon_password "{{RCON_PASSWORD}}"',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi HL2DM'],
                ['key' => 'MAX_PLAYERS', 'value' => '24'],
                ['key' => 'RCON_PASSWORD', 'value' => 'change-me'],
            ],
            [
                [
                    'path' => 'hl2mp/cfg/server.cfg',
                    'description' => 'Base server configuration',
                ],
            ],
            [],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 232370 validate +quit',
            'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 232370 +quit',
            ['+map', '+maxplayers']
        );

        $this->insertTemplate(
            'l4d2',
            'Left 4 Dead 2 Dedicated Server',
            'SteamCMD install with base server config.',
            222860,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                ['name' => 'rcon', 'label' => 'RCON', 'protocol' => 'tcp'],
            ],
            '{{INSTANCE_DIR}}/srcds_run -game left4dead2 +map c1m1_hotel +maxplayers {{MAX_PLAYERS}} +hostname "{{SERVER_NAME}}" +rcon_password "{{RCON_PASSWORD}}"',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi L4D2'],
                ['key' => 'MAX_PLAYERS', 'value' => '8'],
                ['key' => 'RCON_PASSWORD', 'value' => 'change-me'],
            ],
            [
                [
                    'path' => 'left4dead2/cfg/server.cfg',
                    'description' => 'Base server configuration',
                ],
            ],
            [
                'left4dead2/addons/metamod',
                'left4dead2/addons/sourcemod',
            ],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +@sSteamCmdForcePlatformType windows +app_update 222860 validate +quit && steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +@sSteamCmdForcePlatformType linux +app_update 222860 validate +quit',
            'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +@sSteamCmdForcePlatformType windows +app_update 222860 +quit && steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +@sSteamCmdForcePlatformType linux +app_update 222860 +quit',
            ['+map', '+maxplayers']
        );

        $this->insertTemplate(
            'l4d2_windows',
            'Left 4 Dead 2 Dedicated Server (Windows)',
            'SteamCMD install with Windows server binary.',
            222860,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                ['name' => 'rcon', 'label' => 'RCON', 'protocol' => 'tcp'],
            ],
            '{{INSTANCE_DIR}}/srcds.exe -game left4dead2 +map c1m1_hotel +maxplayers {{MAX_PLAYERS}} +hostname "{{SERVER_NAME}}" +rcon_password "{{RCON_PASSWORD}}"',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi L4D2'],
                ['key' => 'MAX_PLAYERS', 'value' => '8'],
                ['key' => 'RCON_PASSWORD', 'value' => 'change-me'],
            ],
            [
                [
                    'path' => 'left4dead2/cfg/server.cfg',
                    'description' => 'Base server configuration',
                ],
            ],
            [
                'left4dead2/addons/metamod',
                'left4dead2/addons/sourcemod',
            ],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 222860 validate +quit',
            'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 222860 +quit',
            ['+map', '+maxplayers']
        );

        $this->insertTemplate(
            'l4d',
            'Left 4 Dead Dedicated Server',
            'SteamCMD install with base server config.',
            222840,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                ['name' => 'rcon', 'label' => 'RCON', 'protocol' => 'tcp'],
            ],
            '{{INSTANCE_DIR}}/srcds_run -game left4dead +map l4d_hospital01_apartment +maxplayers {{MAX_PLAYERS}} +hostname "{{SERVER_NAME}}" +rcon_password "{{RCON_PASSWORD}}"',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi L4D'],
                ['key' => 'MAX_PLAYERS', 'value' => '8'],
                ['key' => 'RCON_PASSWORD', 'value' => 'change-me'],
            ],
            [
                [
                    'path' => 'left4dead/cfg/server.cfg',
                    'description' => 'Base server configuration',
                ],
            ],
            [
                'left4dead/addons/metamod',
                'left4dead/addons/sourcemod',
            ],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 222840 validate +quit',
            'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 222840 +quit',
            ['+map', '+maxplayers']
        );

        $this->insertTemplate(
            'l4d_windows',
            'Left 4 Dead Dedicated Server (Windows)',
            'SteamCMD install with Windows server binary.',
            222840,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                ['name' => 'rcon', 'label' => 'RCON', 'protocol' => 'tcp'],
            ],
            '{{INSTANCE_DIR}}/srcds.exe -game left4dead +map l4d_hospital01_apartment +maxplayers {{MAX_PLAYERS}} +hostname "{{SERVER_NAME}}" +rcon_password "{{RCON_PASSWORD}}"',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi L4D'],
                ['key' => 'MAX_PLAYERS', 'value' => '8'],
                ['key' => 'RCON_PASSWORD', 'value' => 'change-me'],
            ],
            [
                [
                    'path' => 'left4dead/cfg/server.cfg',
                    'description' => 'Base server configuration',
                ],
            ],
            [
                'left4dead/addons/metamod',
                'left4dead/addons/sourcemod',
            ],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 222840 validate +quit',
            'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 222840 +quit',
            ['+map', '+maxplayers']
        );

        $this->insertTemplate(
            'dods',
            'Day of Defeat: Source Dedicated Server',
            'SteamCMD install with base server config.',
            232290,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                ['name' => 'rcon', 'label' => 'RCON', 'protocol' => 'tcp'],
            ],
            '{{INSTANCE_DIR}}/srcds_run -game dod +map dod_anzio +maxplayers {{MAX_PLAYERS}} +hostname "{{SERVER_NAME}}" +rcon_password "{{RCON_PASSWORD}}"',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi DoD:S'],
                ['key' => 'MAX_PLAYERS', 'value' => '24'],
                ['key' => 'RCON_PASSWORD', 'value' => 'change-me'],
            ],
            [
                [
                    'path' => 'dod/cfg/server.cfg',
                    'description' => 'Base server configuration',
                ],
            ],
            [
                'dod/addons/metamod',
                'dod/addons/sourcemod',
            ],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 232290 validate +quit',
            'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 232290 +quit',
            ['+map', '+maxplayers']
        );

        $this->insertTemplate(
            'dods_windows',
            'Day of Defeat: Source Dedicated Server (Windows)',
            'SteamCMD install with Windows server binary.',
            232290,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                ['name' => 'rcon', 'label' => 'RCON', 'protocol' => 'tcp'],
            ],
            '{{INSTANCE_DIR}}/srcds.exe -game dod +map dod_anzio +maxplayers {{MAX_PLAYERS}} +hostname "{{SERVER_NAME}}" +rcon_password "{{RCON_PASSWORD}}"',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi DoD:S'],
                ['key' => 'MAX_PLAYERS', 'value' => '24'],
                ['key' => 'RCON_PASSWORD', 'value' => 'change-me'],
            ],
            [
                [
                    'path' => 'dod/cfg/server.cfg',
                    'description' => 'Base server configuration',
                ],
            ],
            [
                'dod/addons/metamod',
                'dod/addons/sourcemod',
            ],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 232290 validate +quit',
            'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 232290 +quit',
            ['+map', '+maxplayers']
        );

        $this->insertPlugin(
            'minecraft_java',
            'EssentialsX',
            '2.20.1',
            'sha256:3f1a4a7991e2457c8b5e5a7b5b3f0e59a2f266f2fd5a1d7f6b82bbf83cb5e6c2',
            'https://github.com/EssentialsX/Essentials/releases/download/2.20.1/EssentialsX-2.20.1.jar',
            'Core commands and moderation suite.'
        );

        $this->insertPlugin(
            'minecraft_java',
            'ViaVersion',
            '5.0.0',
            'sha256:2c3f1b6c6c8e4c7e3c1d6d5c8b9c7e7f7e0a2f3b4c5d6e7f8a9b0c1d2e3f4a5',
            'https://github.com/ViaVersion/ViaVersion/releases/download/5.0.0/ViaVersion-5.0.0.jar',
            'Protocol compatibility layer for mixed client versions.'
        );

        $this->insertPlugin(
            'minecraft_java',
            'LuckPerms',
            '5.4.138',
            'sha256:0fdc75f2603c9b975a5e1f9bfe1b6d2e1c0e43e2a4efb0d4c39d296f04a5e1d8',
            'https://github.com/LuckPerms/LuckPerms/releases/download/v5.4.138/LuckPerms-Bukkit-5.4.138.jar',
            'Permissions management plugin.'
        );

        $this->insertPlugin(
            'minecraft_java',
            'PlaceholderAPI',
            '2.11.6',
            'sha256:4d1c2e3f4a5b6c7d8e9f0a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1',
            'https://github.com/PlaceholderAPI/PlaceholderAPI/releases/download/2.11.6/PlaceholderAPI-2.11.6.jar',
            'Placeholder support for chat and scoreboards.'
        );

        $this->insertPlugin(
            'minecraft_java',
            'WorldEdit',
            '7.3.4',
            'sha256:fbfc2c2a1f2f2a2d8b2e1846b3f6e0e6b8f2c6245a2f3c6a394e228bc41a786a',
            'https://github.com/EngineHub/WorldEdit/releases/download/7.3.4/worldedit-bukkit-7.3.4.jar',
            'In-game map editor and builder tools.'
        );

        $this->insertPlugin(
            'minecraft_java',
            'Vault',
            '1.7.3',
            'sha256:8f6b8b7bcd1fd6b2d9f9a16d637e9a3b61abdb928f8d410911a8b905c71e7021',
            'https://github.com/MilkBowl/Vault/releases/download/1.7.3/Vault.jar',
            'Economy and permissions bridge.'
        );

        $this->insertPlugin(
            'minecraft_java',
            'Geyser',
            '2.4.1',
            'sha256:8f0e1d2c3b4a59687766554433221100ffeeddccbbaa99887766554433221100',
            'https://github.com/GeyserMC/Geyser/releases/download/v2.4.1/Geyser-Spigot.jar',
            'Allow Bedrock clients to join Java servers.'
        );

        $this->insertPlugin(
            'minecraft_java',
            'Floodgate',
            '2.2.3',
            'sha256:1a2b3c4d5e6f7081928374655647382910ffeeddccbbaa998877665544332211',
            'https://github.com/GeyserMC/Floodgate/releases/download/v2.2.3/Floodgate-Spigot.jar',
            'Authentication bridge for Geyser Bedrock players.'
        );

        $this->insertPlugin(
            'cs2',
            'MetaMod:Source',
            '2.0.0',
            'sha256:2157a4a2a7a5b93e1d3e9c0a12a0b0b3f6b5f4d6f4e81375a944f9b6132f9b23',
            'https://mms.alliedmods.net/mmsdrop/2.0/mmsource-2.0.0-git1247-linux.tar.gz',
            'Core plugin loader for Source-based servers.'
        );

        $this->insertPlugin(
            'cs2',
            'CounterStrikeSharp',
            '1.0.197',
            'sha256:3c0a47f2e904d06a6ce7f2ad6a4c2969a9b76f513f3f067d09c8b4b9b9d7a14e',
            'https://github.com/roflmuffin/CounterStrikeSharp/releases/download/v1.0.197/counterstrikesharp-linux.zip',
            'Managed plugin framework for CS2.'
        );

        $this->insertPlugin(
            'rust',
            'uMod (Oxide)',
            '2.0.6334',
            'sha256:7b6f2c7b81e7a4c6f4868f0b2f3eb8d1a1b9c2b1c9d1a0d5a3e5f9b2e1c7f3a4',
            'https://umod.org/games/rust/download/develop',
            'Modding framework for Rust servers.'
        );
    }

    public function down(Schema $schema): void
    {
        return;
        $this->deletePlugin('minecraft_java', 'EssentialsX');
        $this->deletePlugin('minecraft_java', 'ViaVersion');
        $this->deletePlugin('minecraft_java', 'LuckPerms');
        $this->deletePlugin('minecraft_java', 'PlaceholderAPI');
        $this->deletePlugin('minecraft_java', 'WorldEdit');
        $this->deletePlugin('minecraft_java', 'Vault');
        $this->deletePlugin('minecraft_java', 'Geyser');
        $this->deletePlugin('minecraft_java', 'Floodgate');
        $this->deletePlugin('cs2', 'MetaMod:Source');
        $this->deletePlugin('cs2', 'CounterStrikeSharp');
        $this->deletePlugin('rust', 'uMod (Oxide)');

        $this->deleteTemplate('palworld');
        $this->deleteTemplate('palworld_windows');
        $this->deleteTemplate('garrys_mod');
        $this->deleteTemplate('terraria');
        $this->deleteTemplate('seven_days_to_die');
        $this->deleteTemplate('factorio');
        $this->deleteTemplate('valheim_windows');
        $this->deleteTemplate('satisfactory_windows');
        $this->deleteTemplate('dayz');
        $this->deleteTemplate('dayz_windows');
        $this->deleteTemplate('v_rising');
        $this->deleteTemplate('v_rising_windows');
        $this->deleteTemplate('enshrouded_windows');
        $this->deleteTemplate('minecraft_java_1_19_4');
        $this->deleteTemplate('minecraft_java_1_18_2');
        $this->deleteTemplate('minecraft_java_windows');
        $this->deleteTemplate('minecraft_java_1_19_4_windows');
        $this->deleteTemplate('minecraft_java_1_18_2_windows');
        $this->deleteTemplate('cs2_windows');
        $this->deleteTemplate('csgo_legacy_windows');
        $this->deleteTemplate('tf2');
        $this->deleteTemplate('tf2_windows');
        $this->deleteTemplate('css');
        $this->deleteTemplate('css_windows');
        $this->deleteTemplate('hl2dm');
        $this->deleteTemplate('hl2dm_windows');
        $this->deleteTemplate('l4d2');
        $this->deleteTemplate('l4d2_windows');
        $this->deleteTemplate('l4d');
        $this->deleteTemplate('l4d_windows');
        $this->deleteTemplate('dods');
        $this->deleteTemplate('dods_windows');
        $this->deleteTemplate('project_zomboid');
        $this->deleteTemplate('project_zomboid_windows');
        $this->deleteTemplate('conan_exiles');
        $this->deleteTemplate('conan_exiles_windows');
        $this->deleteTemplate('arma3');
        $this->deleteTemplate('arma3_windows');
    }

    private function insertTemplate(
        string $gameKey,
        string $displayName,
        ?string $description,
        ?int $steamAppId,
        ?string $sniperProfile,
        array $requiredPorts,
        string $startParams,
        array $envVars,
        array $configFiles,
        array $pluginPaths,
        array $fastdlSettings,
        string $installCommand,
        string $updateCommand,
        array $allowedSwitchFlags,
    ): void {
        $columns = [
            'game_key',
            'display_name',
            'description',
            'steam_app_id',
            'sniper_profile',
            'required_ports',
            'start_params',
            'env_vars',
            'config_files',
            'plugin_paths',
            'fastdl_settings',
            'install_command',
            'update_command',
            'allowed_switch_flags',
        ];

        $values = [
            $this->quote($gameKey),
            $this->quote($displayName),
            $this->quote($description),
            $steamAppId === null ? 'NULL' : (string) $steamAppId,
            $this->quote($sniperProfile),
            $this->quoteJson($requiredPorts),
            $this->quote($startParams),
            $this->quoteJson($envVars),
            $this->quoteJson($configFiles),
            $this->quoteJson($pluginPaths),
            $this->quoteJson($fastdlSettings),
            $this->quote($installCommand),
            $this->quote($updateCommand),
            $this->quoteJson($allowedSwitchFlags),
        ];

        if ($this->hasColumn('game_templates', 'supported_os')) {
            $columns[] = 'supported_os';
            $columns[] = 'port_profile';
            $columns[] = 'requirements';
            $values[] = $this->quoteJson($this->resolveSupportedOs($gameKey));
            $values[] = $this->quoteJson($this->buildPortProfile($requiredPorts));
            $values[] = $this->quoteJson($this->buildRequirements($gameKey, $steamAppId, $envVars));
        }

        $columns[] = 'created_at';
        $columns[] = 'updated_at';
        $values[] = $this->currentTimestampExpression();
        $values[] = $this->currentTimestampExpression();

        $sql = sprintf(
            'INSERT INTO game_templates (%s) SELECT %s WHERE NOT EXISTS (SELECT 1 FROM game_templates WHERE game_key = %s)',
            implode(', ', $columns),
            implode(', ', $values),
            $this->quote($gameKey),
        );

        $this->addSql($sql);
    }

    private function insertPlugin(
        string $templateGameKey,
        string $name,
        string $version,
        string $checksum,
        string $downloadUrl,
        ?string $description,
    ): void {
        $sql = sprintf(
            'INSERT INTO game_template_plugins (template_id, name, version, checksum, download_url, description, created_at, updated_at) '
            . 'SELECT template.id, %s, %s, %s, %s, %s, %s, %s '
            . 'FROM game_templates template '
            . 'WHERE template.game_key = %s '
            . 'AND NOT EXISTS (SELECT 1 FROM game_template_plugins WHERE template_id = template.id AND name = %s)',
            $this->quote($name),
            $this->quote($version),
            $this->quote($checksum),
            $this->quote($downloadUrl),
            $this->quote($description),
            $this->currentTimestampExpression(),
            $this->currentTimestampExpression(),
            $this->quote($templateGameKey),
            $this->quote($name),
        );

        $this->addSql($sql);
    }

    private function deletePlugin(string $templateGameKey, string $name): void
    {
        $this->addSql(sprintf(
            'DELETE FROM game_template_plugins WHERE name = %s AND template_id IN (SELECT id FROM game_templates WHERE game_key = %s)',
            $this->quote($name),
            $this->quote($templateGameKey),
        ));
    }

    private function deleteTemplate(string $gameKey): void
    {
        $this->addSql(sprintf(
            'DELETE FROM game_templates WHERE game_key = %s',
            $this->quote($gameKey),
        ));
    }

    private function quote(?string $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        return $this->connection->quote($value);
    }

    private function quoteJson(array $value): string
    {
        return $this->connection->quote($this->jsonEncode($value));
    }

    private function jsonEncode(array $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '[]' : $encoded;
    }

    /**
     * @param array<int, array<string, mixed>> $requiredPorts
     * @return array<int, array<string, mixed>>
     */
    private function buildPortProfile(array $requiredPorts): array
    {
        $roleMap = [
            'game' => 'game',
            'query' => 'query',
            'rcon' => 'rcon',
            'tv' => 'tv',
            'voice' => 'voice',
            'filetransfer' => 'filetransfer',
        ];

        $profile = [];
        foreach ($requiredPorts as $port) {
            if (!is_array($port)) {
                continue;
            }
            $name = strtolower((string) ($port['name'] ?? 'game'));
            $role = $roleMap[$name] ?? $name;
            $protocol = (string) ($port['protocol'] ?? 'udp');
            $count = (int) ($port['count'] ?? 1);
            if ($count <= 0) {
                $count = 1;
            }

            $profile[] = [
                'role' => $role,
                'protocol' => $protocol,
                'count' => $count,
                'required' => isset($port['required']) ? (bool) $port['required'] : true,
                'contiguous' => isset($port['contiguous']) ? (bool) $port['contiguous'] : false,
            ];
        }

        return $profile;
    }

    /**
     * @param array<int, array<string, mixed>> $envVars
     * @return array<string, mixed>
     */
    private function buildRequirements(string $gameKey, ?int $steamAppId, array $envVars): array
    {
        $envVarKeys = $this->extractEnvVarKeys($envVars);
        $requiredSecrets = $this->isCsTemplate($gameKey) ? ['STEAM_GSLT'] : [];

        return [
            'required_vars' => $envVarKeys,
            'required_secrets' => $requiredSecrets,
            'steam_install_mode' => $this->resolveSteamInstallMode($gameKey, $steamAppId),
            'customer_allowed_vars' => $envVarKeys,
            'customer_allowed_secrets' => $requiredSecrets,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $envVars
     * @return array<int, string>
     */
    private function extractEnvVarKeys(array $envVars): array
    {
        $keys = [];
        foreach ($envVars as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $key = trim((string) ($entry['key'] ?? ''));
            if ($key !== '') {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @return array<int, string>
     */
    private function resolveSupportedOs(string $gameKey): array
    {
        return str_ends_with($gameKey, '_windows') ? ['windows'] : ['linux'];
    }

    private function resolveSteamInstallMode(string $gameKey, ?int $steamAppId): string
    {
        if ($this->isMinecraftNoSteam($gameKey)) {
            return 'none';
        }

        return $steamAppId !== null ? 'anonymous' : 'none';
    }

    private function isMinecraftNoSteam(string $gameKey): bool
    {
        return in_array($gameKey, [
            'minecraft_paper',
            'minecraft_vanilla',
            'minecraft_paper_windows',
            'minecraft_vanilla_windows',
            'minecraft_paper_all',
            'minecraft_vanilla_all',
            'minecraft_bedrock',
        ], true);
    }

    private function isCsTemplate(string $gameKey): bool
    {
        return in_array($gameKey, [
            'cs2',
            'csgo_legacy',
            'cs2_windows',
            'csgo_legacy_windows',
        ], true);
    }

    private function currentTimestampExpression(): string
    {
        return $this->isSqlite() ? 'CURRENT_TIMESTAMP' : 'CURRENT_TIMESTAMP()';
    }

    private function isSqlite(): bool
    {
        $platform = $this->connection->getDatabasePlatform();

        if (method_exists($platform, 'getName')) {
            return in_array($platform->getName(), ['sqlite', 'sqlite3'], true);
        }

        return $platform instanceof \Doctrine\DBAL\Platforms\SqlitePlatform
            || $platform instanceof \Doctrine\DBAL\Platforms\SQLitePlatform;
    }

    private function hasColumn(string $table, string $column): bool
    {
        $columns = $this->connection->createSchemaManager()->listTableColumns($table);

        return array_key_exists($column, $columns);
    }
}


final class Version20250311120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add audit logs table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE audit_logs (id INT AUTO_INCREMENT NOT NULL, actor_id INT DEFAULT NULL, action VARCHAR(120) NOT NULL, payload JSON NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', hash_prev VARCHAR(64) DEFAULT NULL, hash_current VARCHAR(64) NOT NULL, INDEX IDX_AUDIT_LOGS_ACTOR (actor_id), INDEX idx_audit_logs_created_at (created_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE audit_logs ADD CONSTRAINT FK_AUDIT_LOGS_ACTOR FOREIGN KEY (actor_id) REFERENCES users (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE audit_logs DROP FOREIGN KEY FK_AUDIT_LOGS_ACTOR');
        $this->addSql('DROP TABLE audit_logs');
    }
}


final class Version20250311123000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create missing core tables for billing, services, and support.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE `databases` (id INT AUTO_INCREMENT NOT NULL, customer_id INT NOT NULL, engine VARCHAR(30) NOT NULL, host VARCHAR(255) NOT NULL, port INT NOT NULL, name VARCHAR(190) NOT NULL, username VARCHAR(190) NOT NULL, encrypted_password JSON NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_databases_customer_id (customer_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE api_tokens (id INT AUTO_INCREMENT NOT NULL, customer_id INT NOT NULL, name VARCHAR(190) NOT NULL, token_prefix VARCHAR(16) NOT NULL, token_hash VARCHAR(64) NOT NULL, encrypted_token JSON NOT NULL, scopes JSON NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', last_used_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', expires_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', revoked_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', rotated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_api_tokens_customer_id (customer_id), INDEX idx_api_tokens_token_hash (token_hash), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE backup_definitions (id INT AUTO_INCREMENT NOT NULL, customer_id INT NOT NULL, target_type VARCHAR(20) NOT NULL, target_id VARCHAR(64) NOT NULL, label VARCHAR(120) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_backup_definitions_customer_id (customer_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE backup_schedules (id INT AUTO_INCREMENT NOT NULL, definition_id INT NOT NULL, cron_expression VARCHAR(120) NOT NULL, retention_days INT NOT NULL, retention_count INT NOT NULL, enabled TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_backup_schedules_definition (definition_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE ddos_provider_credentials (id INT AUTO_INCREMENT NOT NULL, customer_id INT NOT NULL, provider VARCHAR(60) NOT NULL, encrypted_api_key JSON NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_ddos_provider_customer (customer_id, provider), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE port_pools (id INT AUTO_INCREMENT NOT NULL, node_id VARCHAR(64) NOT NULL, name VARCHAR(120) NOT NULL, start_port INT NOT NULL, end_port INT NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_port_pools_node_id (node_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE port_blocks (id VARCHAR(32) NOT NULL, pool_id INT NOT NULL, customer_id INT NOT NULL, instance_id INT DEFAULT NULL, start_port INT NOT NULL, end_port INT NOT NULL, assigned_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', released_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_port_blocks_pool_id (pool_id), INDEX idx_port_blocks_customer_id (customer_id), INDEX idx_port_blocks_instance_id (instance_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE webspaces (id INT AUTO_INCREMENT NOT NULL, customer_id INT NOT NULL, node_id VARCHAR(64) NOT NULL, path VARCHAR(255) NOT NULL, php_version VARCHAR(20) NOT NULL, quota INT NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_webspaces_customer_id (customer_id), INDEX idx_webspaces_node_id (node_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE domains (id INT AUTO_INCREMENT NOT NULL, customer_id INT NOT NULL, webspace_id INT NOT NULL, name VARCHAR(255) NOT NULL, status VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', ssl_expires_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_domains_customer_id (customer_id), INDEX idx_domains_webspace_id (webspace_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE dns_records (id INT AUTO_INCREMENT NOT NULL, domain_id INT NOT NULL, name VARCHAR(255) NOT NULL, type VARCHAR(12) NOT NULL, content VARCHAR(255) NOT NULL, ttl INT NOT NULL, priority INT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_dns_records_domain_id (domain_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE mail_aliases (id INT AUTO_INCREMENT NOT NULL, customer_id INT NOT NULL, domain_id INT NOT NULL, local_part VARCHAR(190) NOT NULL, address VARCHAR(255) NOT NULL, destinations JSON NOT NULL, enabled TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_mail_aliases_customer_id (customer_id), INDEX idx_mail_aliases_domain_id (domain_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE mailboxes (id INT AUTO_INCREMENT NOT NULL, customer_id INT NOT NULL, domain_id INT NOT NULL, local_part VARCHAR(190) NOT NULL, address VARCHAR(255) NOT NULL, password_hash VARCHAR(255) NOT NULL, secret_payload JSON NOT NULL, quota INT NOT NULL, enabled TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_mailboxes_customer_id (customer_id), INDEX idx_mailboxes_domain_id (domain_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE user_sessions (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, token_hash VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', last_used_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', expires_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', revoked_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_user_sessions_user_id (user_id), INDEX idx_user_sessions_token_hash (token_hash), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE jobs (id VARCHAR(32) NOT NULL, type VARCHAR(120) NOT NULL, payload JSON NOT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', locked_by VARCHAR(120) DEFAULT NULL, locked_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', lock_token VARCHAR(64) DEFAULT NULL, lock_expires_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_jobs_status (status), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE job_results (id INT AUTO_INCREMENT NOT NULL, job_id VARCHAR(32) NOT NULL, status VARCHAR(20) NOT NULL, output JSON NOT NULL, completed_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_job_results_job (job_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE log_indices (id VARCHAR(32) NOT NULL, agent_id VARCHAR(64) DEFAULT NULL, source VARCHAR(20) NOT NULL, scope_type VARCHAR(40) NOT NULL, scope_id VARCHAR(64) NOT NULL, log_name VARCHAR(80) NOT NULL, file_path VARCHAR(255) NOT NULL, byte_offset BIGINT NOT NULL, last_indexed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX log_indices_identity (agent_id, source, scope_type, scope_id, log_name), INDEX idx_log_indices_agent_id (agent_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE firewall_states (id INT AUTO_INCREMENT NOT NULL, node_id VARCHAR(64) NOT NULL, ports JSON NOT NULL, updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_firewall_states_node (node_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE instance_schedules (id INT AUTO_INCREMENT NOT NULL, instance_id INT NOT NULL, customer_id INT NOT NULL, action VARCHAR(20) NOT NULL, cron_expression VARCHAR(120) NOT NULL, time_zone VARCHAR(64) DEFAULT NULL, enabled TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_instance_schedules_instance_id (instance_id), INDEX idx_instance_schedules_customer_id (customer_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE metric_samples (id INT AUTO_INCREMENT NOT NULL, agent_id VARCHAR(64) NOT NULL, recorded_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', cpu_percent DOUBLE PRECISION DEFAULT NULL, memory_percent DOUBLE PRECISION DEFAULT NULL, disk_percent DOUBLE PRECISION DEFAULT NULL, net_bytes_sent BIGINT DEFAULT NULL, net_bytes_recv BIGINT DEFAULT NULL, payload JSON DEFAULT NULL, INDEX idx_metric_samples_agent_id (agent_id), INDEX idx_metric_samples_recorded_at (recorded_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE tickets (id INT AUTO_INCREMENT NOT NULL, customer_id INT NOT NULL, subject VARCHAR(160) NOT NULL, category VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, priority VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', last_message_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_tickets_customer_id (customer_id), INDEX idx_tickets_status (status), INDEX idx_tickets_last_message_at (last_message_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE ticket_messages (id INT AUTO_INCREMENT NOT NULL, ticket_id INT NOT NULL, author_id INT NOT NULL, body LONGTEXT NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_ticket_messages_ticket_id (ticket_id), INDEX idx_ticket_messages_author_id (author_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE payments (id INT AUTO_INCREMENT NOT NULL, invoice_id INT NOT NULL, provider VARCHAR(60) NOT NULL, reference VARCHAR(120) NOT NULL, amount_cents INT NOT NULL, currency VARCHAR(3) NOT NULL, status VARCHAR(20) NOT NULL, received_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_payments_invoice_id (invoice_id), INDEX idx_payments_status (status), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE dunning_reminders (id INT AUTO_INCREMENT NOT NULL, invoice_id INT NOT NULL, level INT NOT NULL, fee_cents INT NOT NULL, grace_days INT NOT NULL, status VARCHAR(20) NOT NULL, sent_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_dunning_reminders_invoice_id (invoice_id), INDEX idx_dunning_reminders_status (status), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE ts3_instances (id INT AUTO_INCREMENT NOT NULL, customer_id INT NOT NULL, node_id VARCHAR(64) NOT NULL, name VARCHAR(80) NOT NULL, voice_port INT NOT NULL, query_port INT NOT NULL, file_port INT NOT NULL, database_mode VARCHAR(20) NOT NULL, database_host VARCHAR(120) DEFAULT NULL, database_port INT DEFAULT NULL, database_name VARCHAR(120) DEFAULT NULL, database_username VARCHAR(120) DEFAULT NULL, database_password JSON DEFAULT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_ts3_instances_customer_id (customer_id), INDEX idx_ts3_instances_node_id (node_id), INDEX idx_ts3_instances_status (status), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE module_settings (module_key VARCHAR(40) NOT NULL, version VARCHAR(20) NOT NULL, enabled TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', PRIMARY KEY(module_key)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE tenants (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, branding JSON NOT NULL, domains JSON NOT NULL, mail_hostname VARCHAR(255) NOT NULL, invoice_prefix VARCHAR(40) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE `databases` ADD CONSTRAINT FK_DATABASES_CUSTOMER FOREIGN KEY (customer_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE api_tokens ADD CONSTRAINT FK_API_TOKENS_CUSTOMER FOREIGN KEY (customer_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE backup_definitions ADD CONSTRAINT FK_BACKUP_DEFINITIONS_CUSTOMER FOREIGN KEY (customer_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE backup_schedules ADD CONSTRAINT FK_BACKUP_SCHEDULES_DEFINITION FOREIGN KEY (definition_id) REFERENCES backup_definitions (id)');
        $this->addSql('ALTER TABLE ddos_provider_credentials ADD CONSTRAINT FK_DDOS_PROVIDER_CUSTOMER FOREIGN KEY (customer_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE port_pools ADD CONSTRAINT FK_PORT_POOLS_NODE FOREIGN KEY (node_id) REFERENCES agents (id)');
        $this->addSql('ALTER TABLE port_blocks ADD CONSTRAINT FK_PORT_BLOCKS_POOL FOREIGN KEY (pool_id) REFERENCES port_pools (id)');
        $this->addSql('ALTER TABLE port_blocks ADD CONSTRAINT FK_PORT_BLOCKS_CUSTOMER FOREIGN KEY (customer_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE port_blocks ADD CONSTRAINT FK_PORT_BLOCKS_INSTANCE FOREIGN KEY (instance_id) REFERENCES instances (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE webspaces ADD CONSTRAINT FK_WEBSPACES_CUSTOMER FOREIGN KEY (customer_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE webspaces ADD CONSTRAINT FK_WEBSPACES_NODE FOREIGN KEY (node_id) REFERENCES agents (id)');
        $this->addSql('ALTER TABLE domains ADD CONSTRAINT FK_DOMAINS_CUSTOMER FOREIGN KEY (customer_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE domains ADD CONSTRAINT FK_DOMAINS_WEBSPACE FOREIGN KEY (webspace_id) REFERENCES webspaces (id)');
        $this->addSql('ALTER TABLE dns_records ADD CONSTRAINT FK_DNS_RECORDS_DOMAIN FOREIGN KEY (domain_id) REFERENCES domains (id)');
        $this->addSql('ALTER TABLE mail_aliases ADD CONSTRAINT FK_MAIL_ALIASES_CUSTOMER FOREIGN KEY (customer_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE mail_aliases ADD CONSTRAINT FK_MAIL_ALIASES_DOMAIN FOREIGN KEY (domain_id) REFERENCES domains (id)');
        $this->addSql('ALTER TABLE mailboxes ADD CONSTRAINT FK_MAILBOXES_CUSTOMER FOREIGN KEY (customer_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE mailboxes ADD CONSTRAINT FK_MAILBOXES_DOMAIN FOREIGN KEY (domain_id) REFERENCES domains (id)');
        $this->addSql('ALTER TABLE user_sessions ADD CONSTRAINT FK_USER_SESSIONS_USER FOREIGN KEY (user_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE job_results ADD CONSTRAINT FK_JOB_RESULTS_JOB FOREIGN KEY (job_id) REFERENCES jobs (id)');
        $this->addSql('ALTER TABLE log_indices ADD CONSTRAINT FK_LOG_INDICES_AGENT FOREIGN KEY (agent_id) REFERENCES agents (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE firewall_states ADD CONSTRAINT FK_FIREWALL_STATES_NODE FOREIGN KEY (node_id) REFERENCES agents (id)');
        $this->addSql('ALTER TABLE instance_schedules ADD CONSTRAINT FK_INSTANCE_SCHEDULES_INSTANCE FOREIGN KEY (instance_id) REFERENCES instances (id)');
        $this->addSql('ALTER TABLE instance_schedules ADD CONSTRAINT FK_INSTANCE_SCHEDULES_CUSTOMER FOREIGN KEY (customer_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE metric_samples ADD CONSTRAINT FK_METRIC_SAMPLES_AGENT FOREIGN KEY (agent_id) REFERENCES agents (id)');
        $this->addSql('ALTER TABLE tickets ADD CONSTRAINT FK_TICKETS_CUSTOMER FOREIGN KEY (customer_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE ticket_messages ADD CONSTRAINT FK_TICKET_MESSAGES_TICKET FOREIGN KEY (ticket_id) REFERENCES tickets (id)');
        $this->addSql('ALTER TABLE ticket_messages ADD CONSTRAINT FK_TICKET_MESSAGES_AUTHOR FOREIGN KEY (author_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE payments ADD CONSTRAINT FK_PAYMENTS_INVOICE FOREIGN KEY (invoice_id) REFERENCES invoices (id)');
        $this->addSql('ALTER TABLE dunning_reminders ADD CONSTRAINT FK_DUNNING_REMINDERS_INVOICE FOREIGN KEY (invoice_id) REFERENCES invoices (id)');
        $this->addSql('ALTER TABLE ts3_instances ADD CONSTRAINT FK_TS3_INSTANCES_CUSTOMER FOREIGN KEY (customer_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE ts3_instances ADD CONSTRAINT FK_TS3_INSTANCES_NODE FOREIGN KEY (node_id) REFERENCES agents (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE port_blocks DROP FOREIGN KEY FK_PORT_BLOCKS_INSTANCE');
        $this->addSql('ALTER TABLE port_blocks DROP FOREIGN KEY FK_PORT_BLOCKS_POOL');
        $this->addSql('ALTER TABLE port_blocks DROP FOREIGN KEY FK_PORT_BLOCKS_CUSTOMER');
        $this->addSql('ALTER TABLE port_pools DROP FOREIGN KEY FK_PORT_POOLS_NODE');
        $this->addSql('ALTER TABLE webspaces DROP FOREIGN KEY FK_WEBSPACES_CUSTOMER');
        $this->addSql('ALTER TABLE webspaces DROP FOREIGN KEY FK_WEBSPACES_NODE');
        $this->addSql('ALTER TABLE domains DROP FOREIGN KEY FK_DOMAINS_CUSTOMER');
        $this->addSql('ALTER TABLE domains DROP FOREIGN KEY FK_DOMAINS_WEBSPACE');
        $this->addSql('ALTER TABLE dns_records DROP FOREIGN KEY FK_DNS_RECORDS_DOMAIN');
        $this->addSql('ALTER TABLE mail_aliases DROP FOREIGN KEY FK_MAIL_ALIASES_CUSTOMER');
        $this->addSql('ALTER TABLE mail_aliases DROP FOREIGN KEY FK_MAIL_ALIASES_DOMAIN');
        $this->addSql('ALTER TABLE mailboxes DROP FOREIGN KEY FK_MAILBOXES_CUSTOMER');
        $this->addSql('ALTER TABLE mailboxes DROP FOREIGN KEY FK_MAILBOXES_DOMAIN');
        $this->addSql('ALTER TABLE user_sessions DROP FOREIGN KEY FK_USER_SESSIONS_USER');
        $this->addSql('ALTER TABLE tickets DROP FOREIGN KEY FK_TICKETS_CUSTOMER');
        $this->addSql('ALTER TABLE ticket_messages DROP FOREIGN KEY FK_TICKET_MESSAGES_TICKET');
        $this->addSql('ALTER TABLE ticket_messages DROP FOREIGN KEY FK_TICKET_MESSAGES_AUTHOR');
        $this->addSql('ALTER TABLE payments DROP FOREIGN KEY FK_PAYMENTS_INVOICE');
        $this->addSql('ALTER TABLE dunning_reminders DROP FOREIGN KEY FK_DUNNING_REMINDERS_INVOICE');
        $this->addSql('ALTER TABLE ts3_instances DROP FOREIGN KEY FK_TS3_INSTANCES_CUSTOMER');
        $this->addSql('ALTER TABLE ts3_instances DROP FOREIGN KEY FK_TS3_INSTANCES_NODE');
        $this->addSql('ALTER TABLE firewall_states DROP FOREIGN KEY FK_FIREWALL_STATES_NODE');
        $this->addSql('ALTER TABLE instance_schedules DROP FOREIGN KEY FK_INSTANCE_SCHEDULES_INSTANCE');
        $this->addSql('ALTER TABLE instance_schedules DROP FOREIGN KEY FK_INSTANCE_SCHEDULES_CUSTOMER');
        $this->addSql('ALTER TABLE metric_samples DROP FOREIGN KEY FK_METRIC_SAMPLES_AGENT');
        $this->addSql('ALTER TABLE log_indices DROP FOREIGN KEY FK_LOG_INDICES_AGENT');
        $this->addSql('ALTER TABLE job_results DROP FOREIGN KEY FK_JOB_RESULTS_JOB');
        $this->addSql('ALTER TABLE backup_schedules DROP FOREIGN KEY FK_BACKUP_SCHEDULES_DEFINITION');
        $this->addSql('ALTER TABLE backup_definitions DROP FOREIGN KEY FK_BACKUP_DEFINITIONS_CUSTOMER');
        $this->addSql('ALTER TABLE ddos_provider_credentials DROP FOREIGN KEY FK_DDOS_PROVIDER_CUSTOMER');
        $this->addSql('ALTER TABLE api_tokens DROP FOREIGN KEY FK_API_TOKENS_CUSTOMER');
        $this->addSql('ALTER TABLE `databases` DROP FOREIGN KEY FK_DATABASES_CUSTOMER');

        $this->addSql('DROP TABLE tenants');
        $this->addSql('DROP TABLE module_settings');
        $this->addSql('DROP TABLE ts3_instances');
        $this->addSql('DROP TABLE dunning_reminders');
        $this->addSql('DROP TABLE payments');
        $this->addSql('DROP TABLE ticket_messages');
        $this->addSql('DROP TABLE tickets');
        $this->addSql('DROP TABLE metric_samples');
        $this->addSql('DROP TABLE instance_schedules');
        $this->addSql('DROP TABLE firewall_states');
        $this->addSql('DROP TABLE log_indices');
        $this->addSql('DROP TABLE job_results');
        $this->addSql('DROP TABLE jobs');
        $this->addSql('DROP TABLE user_sessions');
        $this->addSql('DROP TABLE mailboxes');
        $this->addSql('DROP TABLE mail_aliases');
        $this->addSql('DROP TABLE dns_records');
        $this->addSql('DROP TABLE domains');
        $this->addSql('DROP TABLE webspaces');
        $this->addSql('DROP TABLE port_blocks');
        $this->addSql('DROP TABLE port_pools');
        $this->addSql('DROP TABLE ddos_provider_credentials');
        $this->addSql('DROP TABLE backup_schedules');
        $this->addSql('DROP TABLE backup_definitions');
        $this->addSql('DROP TABLE api_tokens');
        $this->addSql('DROP TABLE `databases`');
    }
}


final class Version20250311150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add ticket templates, quick replies, and admin signatures.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ticket_templates (id INT AUTO_INCREMENT NOT NULL, admin_id INT NOT NULL, title VARCHAR(120) NOT NULL, subject VARCHAR(160) NOT NULL, category VARCHAR(20) NOT NULL, priority VARCHAR(20) NOT NULL, body LONGTEXT NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_ticket_templates_admin (admin_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE ticket_quick_replies (id INT AUTO_INCREMENT NOT NULL, admin_id INT NOT NULL, title VARCHAR(120) NOT NULL, body LONGTEXT NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_ticket_quick_replies_admin (admin_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE users ADD admin_signature LONGTEXT DEFAULT NULL');

        $this->addSql('ALTER TABLE ticket_templates ADD CONSTRAINT FK_TICKET_TEMPLATES_ADMIN FOREIGN KEY (admin_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ticket_quick_replies ADD CONSTRAINT FK_TICKET_QUICK_REPLIES_ADMIN FOREIGN KEY (admin_id) REFERENCES users (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ticket_templates DROP FOREIGN KEY FK_TICKET_TEMPLATES_ADMIN');
        $this->addSql('ALTER TABLE ticket_quick_replies DROP FOREIGN KEY FK_TICKET_QUICK_REPLIES_ADMIN');

        $this->addSql('DROP TABLE ticket_templates');
        $this->addSql('DROP TABLE ticket_quick_replies');
        $this->addSql('ALTER TABLE users DROP admin_signature');
    }
}


final class Version20250314120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add DDoS status table for agent reporting.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ddos_statuses (id INT AUTO_INCREMENT NOT NULL, node_id VARCHAR(64) NOT NULL, attack_active TINYINT(1) NOT NULL, packets_per_second INT DEFAULT NULL, connection_count INT DEFAULT NULL, ports JSON NOT NULL, protocols JSON NOT NULL, mode VARCHAR(20) DEFAULT NULL, reported_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_ddos_status_node (node_id), INDEX idx_ddos_status_node (node_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE ddos_statuses ADD CONSTRAINT FK_DDOS_STATUS_NODE FOREIGN KEY (node_id) REFERENCES agents (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ddos_statuses DROP FOREIGN KEY FK_DDOS_STATUS_NODE');
        $this->addSql('DROP TABLE ddos_statuses');
    }
}


final class Version20250315120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add DDoS policy table for node policies.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ddos_policies (id INT AUTO_INCREMENT NOT NULL, node_id VARCHAR(64) NOT NULL, ports JSON NOT NULL, protocols JSON NOT NULL, mode VARCHAR(20) DEFAULT NULL, enabled TINYINT(1) NOT NULL, applied_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_ddos_policy_node (node_id), INDEX idx_ddos_policy_node (node_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE ddos_policies ADD CONSTRAINT FK_DDOS_POLICY_NODE FOREIGN KEY (node_id) REFERENCES agents (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ddos_policies DROP FOREIGN KEY FK_DDOS_POLICY_NODE');
        $this->addSql('DROP TABLE ddos_policies');
    }
}


final class Version20250316120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add software disk limits for instances and node disk protection settings.';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('instances')) {
            $this->addSql("ALTER TABLE instances ADD disk_limit_bytes BIGINT NOT NULL, ADD disk_used_bytes BIGINT NOT NULL DEFAULT 0, ADD disk_state VARCHAR(20) NOT NULL DEFAULT 'ok', ADD disk_last_scanned_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', ADD disk_scan_error LONGTEXT DEFAULT NULL");
            $this->addSql('UPDATE instances SET disk_limit_bytes = disk_limit * 1024 * 1024');
        }

        if ($schema->hasTable('agents')) {
            $this->addSql('ALTER TABLE agents ADD disk_scan_interval_seconds INT NOT NULL DEFAULT 180, ADD disk_warning_percent INT NOT NULL DEFAULT 85, ADD disk_hard_block_percent INT NOT NULL DEFAULT 120, ADD node_disk_protection_threshold_percent INT NOT NULL DEFAULT 5, ADD node_disk_protection_override_until DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('instances')) {
            $this->addSql('ALTER TABLE instances DROP disk_limit_bytes, DROP disk_used_bytes, DROP disk_state, DROP disk_last_scanned_at, DROP disk_scan_error');
        }

        if ($schema->hasTable('agents')) {
            $this->addSql('ALTER TABLE agents DROP disk_scan_interval_seconds, DROP disk_warning_percent, DROP disk_hard_block_percent, DROP node_disk_protection_threshold_percent, DROP node_disk_protection_override_until');
        }
    }
}


final class Version20250317120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add rules column to firewall_states.';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('firewall_states')) {
            $this->addSql('ALTER TABLE firewall_states ADD rules JSON DEFAULT NULL');
            $this->addSql("UPDATE firewall_states SET rules = '[]' WHERE rules IS NULL");
            $this->addSql('ALTER TABLE firewall_states MODIFY rules JSON NOT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('firewall_states')) {
            $this->addSql('ALTER TABLE firewall_states DROP rules');
        }
    }
}


final class Version20250318120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add instance SFTP credentials table.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('instance_sftp_credentials')) {
            $this->addSql('CREATE TABLE instance_sftp_credentials (id INT AUTO_INCREMENT NOT NULL, instance_id INT NOT NULL, username VARCHAR(190) NOT NULL, encrypted_password JSON NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_instance_sftp_credentials_instance (instance_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE instance_sftp_credentials ADD CONSTRAINT fk_instance_sftp_credentials_instance FOREIGN KEY (instance_id) REFERENCES instances (id) ON DELETE CASCADE');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('instance_sftp_credentials')) {
            $this->addSql('ALTER TABLE instance_sftp_credentials DROP FOREIGN KEY fk_instance_sftp_credentials_instance');
            $this->addSql('DROP TABLE instance_sftp_credentials');
        }
    }
}


final class Version20250319120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add backups, job logs, and schedule queue tracking.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('job_logs')) {
            $this->addSql('CREATE TABLE job_logs (id INT AUTO_INCREMENT NOT NULL, job_id VARCHAR(32) NOT NULL, message VARCHAR(255) NOT NULL, progress INT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_job_logs_job (job_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE job_logs ADD CONSTRAINT fk_job_logs_job FOREIGN KEY (job_id) REFERENCES jobs (id) ON DELETE CASCADE');
        }

        if (!$schema->hasTable('backups')) {
            $this->addSql('CREATE TABLE backups (id INT AUTO_INCREMENT NOT NULL, definition_id INT NOT NULL, job_id VARCHAR(32) DEFAULT NULL, status VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', completed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_backups_definition (definition_id), INDEX idx_backups_job (job_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE backups ADD CONSTRAINT fk_backups_definition FOREIGN KEY (definition_id) REFERENCES backup_definitions (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE backups ADD CONSTRAINT fk_backups_job FOREIGN KEY (job_id) REFERENCES jobs (id) ON DELETE SET NULL');
        }

        if ($schema->hasTable('instance_schedules') && !$schema->getTable('instance_schedules')->hasColumn('last_queued_at')) {
            $this->addSql('ALTER TABLE instance_schedules ADD last_queued_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        }

        if ($schema->hasTable('backup_schedules') && !$schema->getTable('backup_schedules')->hasColumn('last_queued_at')) {
            $this->addSql('ALTER TABLE backup_schedules ADD last_queued_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('job_logs')) {
            $this->addSql('ALTER TABLE job_logs DROP FOREIGN KEY fk_job_logs_job');
            $this->addSql('DROP TABLE job_logs');
        }

        if ($schema->hasTable('backups')) {
            $this->addSql('ALTER TABLE backups DROP FOREIGN KEY fk_backups_definition');
            $this->addSql('ALTER TABLE backups DROP FOREIGN KEY fk_backups_job');
            $this->addSql('DROP TABLE backups');
        }

        if ($schema->hasTable('instance_schedules') && $schema->getTable('instance_schedules')->hasColumn('last_queued_at')) {
            $this->addSql('ALTER TABLE instance_schedules DROP last_queued_at');
        }

        if ($schema->hasTable('backup_schedules') && $schema->getTable('backup_schedules')->hasColumn('last_queued_at')) {
            $this->addSql('ALTER TABLE backup_schedules DROP last_queued_at');
        }
    }
}


final class Version20250320120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add game definitions and config schemas.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('game_definitions')) {
            $this->addSql('CREATE TABLE game_definitions (id INT AUTO_INCREMENT NOT NULL, game_key VARCHAR(80) NOT NULL, display_name VARCHAR(120) NOT NULL, description LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_game_definitions_key (game_key), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }

        if (!$schema->hasTable('config_schemas')) {
            $this->addSql('CREATE TABLE config_schemas (id INT AUTO_INCREMENT NOT NULL, game_definition_id INT NOT NULL, config_key VARCHAR(80) NOT NULL, name VARCHAR(160) NOT NULL, format VARCHAR(32) NOT NULL, file_path VARCHAR(255) NOT NULL, schema JSON NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_config_schemas_game (game_definition_id), UNIQUE INDEX uniq_config_schemas_game_key (game_definition_id, config_key), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE config_schemas ADD CONSTRAINT fk_config_schemas_game_definition FOREIGN KEY (game_definition_id) REFERENCES game_definitions (id) ON DELETE CASCADE');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('config_schemas')) {
            $this->addSql('ALTER TABLE config_schemas DROP FOREIGN KEY fk_config_schemas_game_definition');
            $this->addSql('DROP TABLE config_schemas');
        }

        if ($schema->hasTable('game_definitions')) {
            $this->addSql('DROP TABLE game_definitions');
        }
    }
}


final class Version20250321120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add job progress tracking.';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('jobs') && !$schema->getTable('jobs')->hasColumn('progress')) {
            $this->addSql('ALTER TABLE jobs ADD progress INT DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('jobs') && $schema->getTable('jobs')->hasColumn('progress')) {
            $this->addSql('ALTER TABLE jobs DROP progress');
        }
    }
}


final class Version20250322120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add agent bootstrap and registration tokens.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('agent_bootstrap_tokens')) {
	    $this->addSql('CREATE TABLE agent_bootstrap_tokens ( id INT AUTO_INCREMENT NOT NULL,  created_by_id INT DEFAULT NULL,  name VARCHAR(190) NOT NULL,  token_prefix VARCHAR(16) NOT NULL,  token_hash VARCHAR(64) NOT NULL,  encrypted_token JSON NOT NULL,  bound_cidr VARCHAR(64) DEFAULT NULL,  bound_node_name VARCHAR(190) DEFAULT NULL,  created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',  updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',  expires_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',  used_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',  revoked_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',  INDEX IDX_BOOTSTRAP_TOKENS_HASH (token_hash),  INDEX IDX_BOOTSTRAP_TOKENS_CREATED_BY (created_by_id),  PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE agent_bootstrap_tokens ADD CONSTRAINT FK_BOOTSTRAP_TOKENS_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE SET NULL');
        }

        if (!$schema->hasTable('agent_registration_tokens')) {
	    $this->addSql('CREATE TABLE agent_registration_tokens ( id INT AUTO_INCREMENT NOT NULL,  bootstrap_token_id INT DEFAULT NULL,  agent_id VARCHAR(64) NOT NULL,  token_prefix VARCHAR(16) NOT NULL,  token_hash VARCHAR(64) NOT NULL,  encrypted_token JSON NOT NULL,  created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',  expires_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',  used_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',  INDEX IDX_REGISTRATION_TOKENS_HASH (token_hash),  INDEX IDX_REGISTRATION_TOKENS_BOOTSTRAP (bootstrap_token_id),  INDEX IDX_REGISTRATION_TOKENS_AGENT (agent_id),  PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE agent_registration_tokens ADD CONSTRAINT FK_REGISTRATION_TOKENS_BOOTSTRAP FOREIGN KEY (bootstrap_token_id) REFERENCES agent_bootstrap_tokens (id) ON DELETE SET NULL');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('agent_registration_tokens')) {
            $this->addSql('DROP TABLE agent_registration_tokens');
        }

        if ($schema->hasTable('agent_bootstrap_tokens')) {
            $this->addSql('DROP TABLE agent_bootstrap_tokens');
        }
    }
}


final class Version20250323120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add webspace status, docroot, limits, and system username.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('webspaces')) {
            return;
        }

        $this->addSql("ALTER TABLE webspaces ADD status VARCHAR(20) DEFAULT 'active' NOT NULL");
        $this->addSql("ALTER TABLE webspaces ADD docroot VARCHAR(255) DEFAULT '' NOT NULL");
        $this->addSql('ALTER TABLE webspaces ADD disk_limit_bytes INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE webspaces ADD ftp_enabled TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE webspaces ADD sftp_enabled TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql("ALTER TABLE webspaces ADD system_username VARCHAR(64) DEFAULT '' NOT NULL");
        $this->addSql('ALTER TABLE webspaces ADD deleted_at DATETIME DEFAULT NULL COMMENT "(DC2Type:datetime_immutable)"');

        $this->addSql('UPDATE webspaces SET docroot = CONCAT(path, \'/public\') WHERE docroot = \'\' OR docroot IS NULL');
        $this->addSql('UPDATE webspaces SET disk_limit_bytes = 0 WHERE disk_limit_bytes IS NULL');
        $this->addSql('UPDATE webspaces SET ftp_enabled = 0 WHERE ftp_enabled IS NULL');
        $this->addSql('UPDATE webspaces SET sftp_enabled = 0 WHERE sftp_enabled IS NULL');
        $this->addSql('UPDATE webspaces SET system_username = CONCAT(\'ws\', id) WHERE system_username = \'\' OR system_username IS NULL');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('webspaces')) {
            return;
        }

        $this->addSql('ALTER TABLE webspaces DROP status');
        $this->addSql('ALTER TABLE webspaces DROP docroot');
        $this->addSql('ALTER TABLE webspaces DROP disk_limit_bytes');
        $this->addSql('ALTER TABLE webspaces DROP ftp_enabled');
        $this->addSql('ALTER TABLE webspaces DROP sftp_enabled');
        $this->addSql('ALTER TABLE webspaces DROP system_username');
        $this->addSql('ALTER TABLE webspaces DROP deleted_at');
    }
}


final class Version20250323130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add TS6 and virtual server placeholder tables.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('ts6_instances')) {
            $this->addSql('CREATE TABLE ts6_instances (id INT AUTO_INCREMENT NOT NULL, customer_id INT NOT NULL, node_id VARCHAR(64) NOT NULL, name VARCHAR(80) NOT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_ts6_instances_customer_id (customer_id), INDEX idx_ts6_instances_node_id (node_id), INDEX idx_ts6_instances_status (status), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE ts6_instances ADD CONSTRAINT FK_TS6_INSTANCES_CUSTOMER FOREIGN KEY (customer_id) REFERENCES users (id)');
            $this->addSql('ALTER TABLE ts6_instances ADD CONSTRAINT FK_TS6_INSTANCES_NODE FOREIGN KEY (node_id) REFERENCES agents (id)');
        }

        if (!$schema->hasTable('ts_virtual_server')) {
            $this->addSql('CREATE TABLE ts_virtual_server (id INT AUTO_INCREMENT NOT NULL, ts6_instance_id INT NOT NULL, customer_id INT NOT NULL, name VARCHAR(80) NOT NULL, slots INT NOT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_ts_virtual_server_instance (ts6_instance_id), INDEX idx_ts_virtual_server_customer (customer_id), INDEX idx_ts_virtual_server_status (status), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE ts_virtual_server ADD CONSTRAINT FK_TS_VIRTUAL_SERVER_INSTANCE FOREIGN KEY (ts6_instance_id) REFERENCES ts6_instances (id)');
            $this->addSql('ALTER TABLE ts_virtual_server ADD CONSTRAINT FK_TS_VIRTUAL_SERVER_CUSTOMER FOREIGN KEY (customer_id) REFERENCES users (id)');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('ts_virtual_server')) {
            $this->addSql('ALTER TABLE ts_virtual_server DROP FOREIGN KEY FK_TS_VIRTUAL_SERVER_INSTANCE');
            $this->addSql('ALTER TABLE ts_virtual_server DROP FOREIGN KEY FK_TS_VIRTUAL_SERVER_CUSTOMER');
            $this->addSql('DROP TABLE ts_virtual_server');
        }

        if ($schema->hasTable('ts6_instances')) {
            $this->addSql('ALTER TABLE ts6_instances DROP FOREIGN KEY FK_TS6_INSTANCES_CUSTOMER');
            $this->addSql('ALTER TABLE ts6_instances DROP FOREIGN KEY FK_TS6_INSTANCES_NODE');
            $this->addSql('DROP TABLE ts6_instances');
        }
    }
}


final class Version20250324120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add app settings table for installer and admin configuration.';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('app_settings')) {
            return;
        }

        $this->addSql('CREATE TABLE app_settings (setting_key VARCHAR(80) NOT NULL, value JSON DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', PRIMARY KEY(setting_key)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('app_settings')) {
            $this->addSql('DROP TABLE app_settings');
        }
    }
}


final class Version20250325120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add name field to users for superadmin display name.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('users')) {
            return;
        }

        $table = $schema->getTable('users');
        if (!$table->hasColumn('name')) {
            $this->addSql('ALTER TABLE users ADD name VARCHAR(120) DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('users')) {
            return;
        }

        $table = $schema->getTable('users');
        if ($table->hasColumn('name')) {
            $this->addSql('ALTER TABLE users DROP name');
        }
    }
}


final class Version20250326120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add template requirements and instance setup values.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE game_templates ADD requirement_vars JSON DEFAULT NULL, ADD requirement_secrets JSON DEFAULT NULL");
        $this->addSql("UPDATE game_templates SET requirement_vars = '[]' WHERE requirement_vars IS NULL");
        $this->addSql("UPDATE game_templates SET requirement_secrets = '[]' WHERE requirement_secrets IS NULL");
        $this->addSql("ALTER TABLE game_templates MODIFY requirement_vars JSON NOT NULL");
        $this->addSql("ALTER TABLE game_templates MODIFY requirement_secrets JSON NOT NULL");

        $this->addSql("ALTER TABLE instances ADD setup_vars JSON DEFAULT NULL, ADD setup_secrets JSON DEFAULT NULL");
        $this->addSql("UPDATE instances SET setup_vars = '[]' WHERE setup_vars IS NULL");
        $this->addSql("UPDATE instances SET setup_secrets = '[]' WHERE setup_secrets IS NULL");
        $this->addSql("ALTER TABLE instances MODIFY setup_vars JSON NOT NULL");
        $this->addSql("ALTER TABLE instances MODIFY setup_secrets JSON NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE instances DROP setup_vars, DROP setup_secrets');
        $this->addSql('ALTER TABLE game_templates DROP requirement_vars, DROP requirement_secrets');
    }
}


final class Version20250327120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add port ranges for node management.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE port_ranges (id INT AUTO_INCREMENT NOT NULL, node_id VARCHAR(64) NOT NULL, purpose VARCHAR(120) NOT NULL, protocol VARCHAR(8) NOT NULL, start_port INT NOT NULL, end_port INT NOT NULL, enabled TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_port_ranges_node_id (node_id), INDEX idx_port_ranges_protocol (protocol), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE port_ranges ADD CONSTRAINT FK_PORT_RANGES_NODE FOREIGN KEY (node_id) REFERENCES agents (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE port_ranges DROP FOREIGN KEY FK_PORT_RANGES_NODE');
        $this->addSql('DROP TABLE port_ranges');
    }
}


final class Version20250328120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add server SFTP access table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE server_sftp_access (id INT AUTO_INCREMENT NOT NULL, server_id INT NOT NULL, username VARCHAR(64) NOT NULL, enabled TINYINT(1) NOT NULL, password_set_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', `keys` JSON NOT NULL, UNIQUE INDEX uniq_server_sftp_access_server (server_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE server_sftp_access ADD CONSTRAINT FK_SERVER_SFTP_ACCESS_SERVER FOREIGN KEY (server_id) REFERENCES instances (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE server_sftp_access DROP FOREIGN KEY FK_SERVER_SFTP_ACCESS_SERVER');
        $this->addSql('DROP TABLE server_sftp_access');
    }
}


final class Version20250328130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add TS6 node, virtual server, token, and viewer tables.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ts6_nodes (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, agent_base_url VARCHAR(255) NOT NULL, agent_api_token_encrypted LONGTEXT NOT NULL, os_type VARCHAR(16) DEFAULT NULL, download_url VARCHAR(255) NOT NULL, install_path VARCHAR(255) NOT NULL, instance_name VARCHAR(120) NOT NULL, service_name VARCHAR(120) NOT NULL, query_bind_ip VARCHAR(64) NOT NULL, query_https_port INT NOT NULL, installed_version VARCHAR(120) DEFAULT NULL, install_status VARCHAR(32) NOT NULL, running TINYINT(1) NOT NULL, last_error LONGTEXT DEFAULT NULL, admin_username VARCHAR(64) NOT NULL, admin_password_encrypted LONGTEXT DEFAULT NULL, admin_password_shown_once_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE ts6_virtual_servers (id INT AUTO_INCREMENT NOT NULL, node_id INT NOT NULL, customer_id INT NOT NULL, sid INT NOT NULL, name VARCHAR(120) NOT NULL, slots INT NOT NULL, voice_port INT DEFAULT NULL, filetransfer_port INT DEFAULT NULL, status VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', archived_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_TS6_VIRTUAL_SERVERS_NODE (node_id), INDEX IDX_TS6_VIRTUAL_SERVERS_CUSTOMER (customer_id), INDEX IDX_TS6_VIRTUAL_SERVERS_SID (sid), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE ts6_tokens (id INT AUTO_INCREMENT NOT NULL, virtual_server_id INT NOT NULL, token_encrypted LONGTEXT NOT NULL, type VARCHAR(16) NOT NULL, active TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', revoked_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_TS6_TOKENS_SERVER (virtual_server_id), INDEX IDX_TS6_TOKENS_ACTIVE (active), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE ts6_viewers (id INT AUTO_INCREMENT NOT NULL, virtual_server_id INT NOT NULL, public_id VARCHAR(64) NOT NULL, enabled TINYINT(1) NOT NULL, cache_ttl_ms INT NOT NULL, domain_allowlist LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX UNIQ_TS6_VIEWERS_PUBLIC (public_id), UNIQUE INDEX UNIQ_TS6_VIEWERS_SERVER (virtual_server_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE ts6_virtual_servers ADD CONSTRAINT FK_TS6_VIRTUAL_SERVERS_NODE FOREIGN KEY (node_id) REFERENCES ts6_nodes (id)');
        $this->addSql('ALTER TABLE ts6_tokens ADD CONSTRAINT FK_TS6_TOKENS_SERVER FOREIGN KEY (virtual_server_id) REFERENCES ts6_virtual_servers (id)');
        $this->addSql('ALTER TABLE ts6_viewers ADD CONSTRAINT FK_TS6_VIEWERS_SERVER FOREIGN KEY (virtual_server_id) REFERENCES ts6_virtual_servers (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ts6_viewers DROP FOREIGN KEY FK_TS6_VIEWERS_SERVER');
        $this->addSql('ALTER TABLE ts6_tokens DROP FOREIGN KEY FK_TS6_TOKENS_SERVER');
        $this->addSql('ALTER TABLE ts6_virtual_servers DROP FOREIGN KEY FK_TS6_VIRTUAL_SERVERS_NODE');
        $this->addSql('DROP TABLE ts6_viewers');
        $this->addSql('DROP TABLE ts6_tokens');
        $this->addSql('DROP TABLE ts6_virtual_servers');
        $this->addSql('DROP TABLE ts6_nodes');
    }
}


final class Version20250328140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add TS3 node, virtual server, token, and viewer tables.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ts3_nodes (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, agent_base_url VARCHAR(255) NOT NULL, agent_api_token_encrypted LONGTEXT NOT NULL, download_url VARCHAR(255) NOT NULL, install_path VARCHAR(255) NOT NULL, instance_name VARCHAR(120) NOT NULL, service_name VARCHAR(120) NOT NULL, query_bind_ip VARCHAR(64) NOT NULL, query_port INT NOT NULL, installed_version VARCHAR(120) DEFAULT NULL, install_status VARCHAR(32) NOT NULL, running TINYINT(1) NOT NULL, last_error LONGTEXT DEFAULT NULL, admin_username VARCHAR(64) NOT NULL, admin_password_encrypted LONGTEXT DEFAULT NULL, admin_password_shown_once_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE ts3_virtual_servers (id INT AUTO_INCREMENT NOT NULL, node_id INT NOT NULL, customer_id INT NOT NULL, sid INT NOT NULL, name VARCHAR(120) NOT NULL, voice_port INT DEFAULT NULL, filetransfer_port INT DEFAULT NULL, status VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', archived_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_TS3_VIRTUAL_SERVERS_NODE (node_id), INDEX IDX_TS3_VIRTUAL_SERVERS_CUSTOMER (customer_id), INDEX IDX_TS3_VIRTUAL_SERVERS_SID (sid), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE ts3_tokens (id INT AUTO_INCREMENT NOT NULL, virtual_server_id INT NOT NULL, token_encrypted LONGTEXT NOT NULL, type VARCHAR(16) NOT NULL, active TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', revoked_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_TS3_TOKENS_SERVER (virtual_server_id), INDEX IDX_TS3_TOKENS_ACTIVE (active), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE ts3_viewers (id INT AUTO_INCREMENT NOT NULL, virtual_server_id INT NOT NULL, public_id VARCHAR(64) NOT NULL, enabled TINYINT(1) NOT NULL, cache_ttl_ms INT NOT NULL, domain_allowlist LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', UNIQUE INDEX UNIQ_TS3_VIEWERS_PUBLIC (public_id), UNIQUE INDEX UNIQ_TS3_VIEWERS_SERVER (virtual_server_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE ts3_virtual_servers ADD CONSTRAINT FK_TS3_VIRTUAL_SERVERS_NODE FOREIGN KEY (node_id) REFERENCES ts3_nodes (id)');
        $this->addSql('ALTER TABLE ts3_tokens ADD CONSTRAINT FK_TS3_TOKENS_SERVER FOREIGN KEY (virtual_server_id) REFERENCES ts3_virtual_servers (id)');
        $this->addSql('ALTER TABLE ts3_viewers ADD CONSTRAINT FK_TS3_VIEWERS_SERVER FOREIGN KEY (virtual_server_id) REFERENCES ts3_virtual_servers (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ts3_viewers DROP FOREIGN KEY FK_TS3_VIEWERS_SERVER');
        $this->addSql('ALTER TABLE ts3_tokens DROP FOREIGN KEY FK_TS3_TOKENS_SERVER');
        $this->addSql('ALTER TABLE ts3_virtual_servers DROP FOREIGN KEY FK_TS3_VIRTUAL_SERVERS_NODE');
        $this->addSql('DROP TABLE ts3_viewers');
        $this->addSql('DROP TABLE ts3_tokens');
        $this->addSql('DROP TABLE ts3_virtual_servers');
        $this->addSql('DROP TABLE ts3_nodes');
    }
}


final class Version20250328150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add SinusBot nodes and instances tables with TS3 client dependency status.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE sinusbot_nodes (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, agent_base_url VARCHAR(255) NOT NULL, agent_api_token_encrypted LONGTEXT NOT NULL, download_url VARCHAR(255) NOT NULL, install_path VARCHAR(255) NOT NULL, instance_root VARCHAR(255) NOT NULL, web_bind_ip VARCHAR(64) NOT NULL, web_port_base INT NOT NULL, installed_version VARCHAR(120) DEFAULT NULL, install_status VARCHAR(32) NOT NULL, last_error LONGTEXT DEFAULT NULL, admin_username VARCHAR(120) DEFAULT NULL, admin_password_encrypted LONGTEXT DEFAULT NULL, ts3_client_installed TINYINT(1) NOT NULL, ts3_client_version VARCHAR(120) DEFAULT NULL, ts3_client_path VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE sinusbot_instances (id INT AUTO_INCREMENT NOT NULL, node_id INT NOT NULL, customer_id INT NOT NULL, instance_id VARCHAR(64) NOT NULL, name VARCHAR(120) NOT NULL, running TINYINT(1) NOT NULL, web_port INT NOT NULL, public_url VARCHAR(255) DEFAULT NULL, connect_type VARCHAR(8) NOT NULL, connect_host VARCHAR(255) NOT NULL, connect_voice_port INT NOT NULL, connect_server_password_encrypted LONGTEXT DEFAULT NULL, connect_privilege_key_encrypted LONGTEXT DEFAULT NULL, nickname VARCHAR(120) DEFAULT NULL, default_channel VARCHAR(255) DEFAULT NULL, volume INT DEFAULT NULL, autostart TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', archived_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', UNIQUE INDEX UNIQ_9F589B1B9F16E290 (instance_id), INDEX IDX_9F589B1B460D9FD (node_id), INDEX IDX_9F589B1B9395C3F3 (customer_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE sinusbot_instances ADD CONSTRAINT FK_9F589B1B460D9FD FOREIGN KEY (node_id) REFERENCES sinusbot_nodes (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sinusbot_instances DROP FOREIGN KEY FK_9F589B1B460D9FD');
        $this->addSql('DROP TABLE sinusbot_instances');
        $this->addSql('DROP TABLE sinusbot_nodes');
    }
}


final class Version20250329120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add template OS support, port profiles, and requirements metadata.';
    }

    public function up(Schema $schema): void
    {
        if ($this->isSqlite()) {
            $this->addSql('ALTER TABLE game_templates ADD COLUMN supported_os JSON DEFAULT NULL');
            $this->addSql('ALTER TABLE game_templates ADD COLUMN port_profile JSON DEFAULT NULL');
            $this->addSql('ALTER TABLE game_templates ADD COLUMN requirements JSON DEFAULT NULL');
        } else {
            $this->addSql('ALTER TABLE game_templates ADD supported_os JSON DEFAULT NULL, ADD port_profile JSON DEFAULT NULL, ADD requirements JSON DEFAULT NULL');
        }

        $this->addSql("UPDATE game_templates SET supported_os = '[]' WHERE supported_os IS NULL");
        $this->addSql("UPDATE game_templates SET port_profile = '[]' WHERE port_profile IS NULL");
        $this->addSql("UPDATE game_templates SET requirements = '{}' WHERE requirements IS NULL");

        if (!$this->isSqlite()) {
            $this->addSql('ALTER TABLE game_templates MODIFY supported_os JSON NOT NULL');
            $this->addSql('ALTER TABLE game_templates MODIFY port_profile JSON NOT NULL');
            $this->addSql('ALTER TABLE game_templates MODIFY requirements JSON NOT NULL');
        }

        $templates = $this->connection->fetchAllAssociative('SELECT id, game_key, steam_app_id, required_ports, env_vars FROM game_templates');

        foreach ($templates as $template) {
            $gameKey = (string) ($template['game_key'] ?? '');
            $steamAppId = $template['steam_app_id'] !== null ? (int) $template['steam_app_id'] : null;
            $requiredPorts = $this->decodeJsonArray((string) ($template['required_ports'] ?? '[]'));
            $envVars = $this->decodeJsonArray((string) ($template['env_vars'] ?? '[]'));

            $supportedOs = str_ends_with($gameKey, '_windows') ? ['windows'] : ['linux'];
            $portProfile = $this->buildPortProfile($requiredPorts);
            $requirements = $this->buildRequirements($gameKey, $steamAppId, $envVars);

            $this->addSql(sprintf(
                'UPDATE game_templates SET supported_os = %s, port_profile = %s, requirements = %s WHERE id = %d',
                $this->quoteJson($supportedOs),
                $this->quoteJson($portProfile),
                $this->quoteJson($requirements),
                (int) $template['id'],
            ));
        }
    }

    public function down(Schema $schema): void
    {
        if ($this->isSqlite()) {
            $this->addSql('ALTER TABLE game_templates DROP COLUMN supported_os');
            $this->addSql('ALTER TABLE game_templates DROP COLUMN port_profile');
            $this->addSql('ALTER TABLE game_templates DROP COLUMN requirements');
        } else {
            $this->addSql('ALTER TABLE game_templates DROP supported_os, DROP port_profile, DROP requirements');
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function decodeJsonArray(string $value): array
    {
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<int, array<string, mixed>> $requiredPorts
     * @return array<int, array<string, mixed>>
     */
    private function buildPortProfile(array $requiredPorts): array
    {
        $roleMap = [
            'game' => 'game',
            'query' => 'query',
            'rcon' => 'rcon',
            'tv' => 'tv',
            'voice' => 'voice',
            'filetransfer' => 'filetransfer',
        ];

        $profile = [];
        foreach ($requiredPorts as $port) {
            if (!is_array($port)) {
                continue;
            }
            $name = strtolower((string) ($port['name'] ?? 'game'));
            $role = $roleMap[$name] ?? $name;
            $protocol = (string) ($port['protocol'] ?? 'udp');
            $count = (int) ($port['count'] ?? 1);
            if ($count <= 0) {
                $count = 1;
            }

            $profile[] = [
                'role' => $role,
                'protocol' => $protocol,
                'count' => $count,
                'required' => isset($port['required']) ? (bool) $port['required'] : true,
                'contiguous' => isset($port['contiguous']) ? (bool) $port['contiguous'] : false,
            ];
        }

        return $profile;
    }

    /**
     * @param array<int, array<string, mixed>> $envVars
     * @return array<string, mixed>
     */
    private function buildRequirements(string $gameKey, ?int $steamAppId, array $envVars): array
    {
        $envVarKeys = $this->extractEnvVarKeys($envVars);
        $requiredSecrets = $this->isCsTemplate($gameKey) ? ['STEAM_GSLT'] : [];

        return [
            'required_vars' => $envVarKeys,
            'required_secrets' => $requiredSecrets,
            'steam_install_mode' => $this->resolveSteamInstallMode($gameKey, $steamAppId),
            'customer_allowed_vars' => $envVarKeys,
            'customer_allowed_secrets' => $requiredSecrets,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $envVars
     * @return array<int, string>
     */
    private function extractEnvVarKeys(array $envVars): array
    {
        $keys = [];
        foreach ($envVars as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $key = trim((string) ($entry['key'] ?? ''));
            if ($key !== '') {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    private function resolveSteamInstallMode(string $gameKey, ?int $steamAppId): string
    {
        if ($this->isMinecraftNoSteam($gameKey)) {
            return 'none';
        }

        return $steamAppId !== null ? 'anonymous' : 'none';
    }

    private function isMinecraftNoSteam(string $gameKey): bool
    {
        return in_array($gameKey, [
            'minecraft_paper',
            'minecraft_vanilla',
            'minecraft_paper_windows',
            'minecraft_vanilla_windows',
        ], true);
    }

    private function isCsTemplate(string $gameKey): bool
    {
        return in_array($gameKey, [
            'cs2',
            'csgo_legacy',
            'cs2_windows',
            'csgo_legacy_windows',
        ], true);
    }

    private function quoteJson(array $value): string
    {
        return $this->connection->quote($this->jsonEncode($value));
    }

    private function jsonEncode(array $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '[]' : $encoded;
    }

    private function isSqlite(): bool
    {
        $platform = $this->connection->getDatabasePlatform();

        if (method_exists($platform, 'getName')) {
            return in_array($platform->getName(), ['sqlite', 'sqlite3'], true);
        }

        return $platform instanceof \Doctrine\DBAL\Platforms\SqlitePlatform
            || $platform instanceof \Doctrine\DBAL\Platforms\SQLitePlatform;
    }
}


final class Version20250401120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Minecraft version catalog and install resolver metadata.';
    }

    public function up(Schema $schema): void
    {
        if ($this->isSqlite()) {
            $this->addSql('CREATE TABLE minecraft_versions_catalog (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, channel VARCHAR(20) NOT NULL, mc_version VARCHAR(32) NOT NULL, build VARCHAR(32) DEFAULT NULL, download_url CLOB NOT NULL, sha256 VARCHAR(64) DEFAULT NULL, released_at DATETIME DEFAULT NULL, UNIQUE (channel, mc_version, build))');
            $this->addSql('ALTER TABLE game_templates ADD COLUMN install_resolver JSON DEFAULT NULL');
        } else {
            $this->addSql('CREATE TABLE minecraft_versions_catalog (id INT AUTO_INCREMENT NOT NULL, channel VARCHAR(20) NOT NULL, mc_version VARCHAR(32) NOT NULL, build VARCHAR(32) DEFAULT NULL, download_url LONGTEXT NOT NULL, sha256 VARCHAR(64) DEFAULT NULL, released_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_minecraft_versions_catalog (channel, mc_version, build), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE game_templates ADD install_resolver JSON DEFAULT NULL');
        }

        $this->addSql("UPDATE game_templates SET install_resolver = '[]' WHERE install_resolver IS NULL");

        if (!$this->isSqlite()) {
            $this->addSql('ALTER TABLE game_templates MODIFY install_resolver JSON NOT NULL');
        }

        return;

        $this->insertTemplate(
            'minecraft_vanilla_all',
            'Minecraft Java (Vanilla)',
            'Vanilla Minecraft with selectable versions via catalog.',
            null,
            null,
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'tcp'],
            ],
            'java -Xms{{JAVA_XMS}} -Xmx{{JAVA_XMX}} -jar {{INSTANCE_DIR}}/server.jar nogui',
            [
                ['key' => 'JAVA_XMS', 'value' => '1G'],
                ['key' => 'JAVA_XMX', 'value' => '2G'],
            ],
            [
                [
                    'path' => 'eula.txt',
                    'description' => 'Minecraft EULA acceptance',
                    'contents' => "eula=true\n",
                ],
                [
                    'path' => 'server.properties',
                    'description' => 'Base server settings',
                    'contents' => "motd=Easy-Wi Minecraft\nview-distance=10\n",
                ],
            ],
            [],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'echo "Install handled by catalog resolver."',
            'echo "Update handled by catalog resolver."',
            [
                'type' => 'minecraft_vanilla',
            ],
            [],
        );

        $this->insertTemplate(
            'minecraft_paper_all',
            'Minecraft Java (Paper)',
            'PaperMC Minecraft with selectable versions/builds via catalog.',
            null,
            null,
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'tcp'],
            ],
            'java -Xms{{JAVA_XMS}} -Xmx{{JAVA_XMX}} -jar {{INSTANCE_DIR}}/server.jar nogui',
            [
                ['key' => 'JAVA_XMS', 'value' => '1G'],
                ['key' => 'JAVA_XMX', 'value' => '2G'],
            ],
            [
                [
                    'path' => 'eula.txt',
                    'description' => 'Minecraft EULA acceptance',
                    'contents' => "eula=true\n",
                ],
                [
                    'path' => 'server.properties',
                    'description' => 'Base server settings',
                    'contents' => "motd=Easy-Wi Minecraft\nview-distance=10\n",
                ],
            ],
            [],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'echo "Install handled by catalog resolver."',
            'echo "Update handled by catalog resolver."',
            [
                'type' => 'papermc_paper',
            ],
            [],
        );
    }

    public function down(Schema $schema): void
    {
        if ($this->isSqlite()) {
            $this->addSql('DROP TABLE minecraft_versions_catalog');
            $this->addSql('ALTER TABLE game_templates DROP COLUMN install_resolver');
        } else {
            $this->addSql('DROP TABLE minecraft_versions_catalog');
            $this->addSql('ALTER TABLE game_templates DROP install_resolver');
        }
    }

    private function insertTemplate(
        string $gameKey,
        string $displayName,
        ?string $description,
        ?int $steamAppId,
        ?string $sniperProfile,
        array $requiredPorts,
        string $startParams,
        array $envVars,
        array $configFiles,
        array $pluginPaths,
        array $fastdlSettings,
        string $installCommand,
        string $updateCommand,
        array $installResolver,
        array $allowedSwitchFlags,
    ): void {
        $columns = [
            'game_key',
            'display_name',
            'description',
            'steam_app_id',
            'sniper_profile',
            'required_ports',
            'start_params',
            'env_vars',
            'config_files',
            'plugin_paths',
            'fastdl_settings',
            'install_command',
            'update_command',
            'allowed_switch_flags',
        ];

        $values = [
            $this->quote($gameKey),
            $this->quote($displayName),
            $this->quote($description),
            $steamAppId === null ? 'NULL' : (string) $steamAppId,
            $this->quote($sniperProfile),
            $this->quoteJson($requiredPorts),
            $this->quote($startParams),
            $this->quoteJson($envVars),
            $this->quoteJson($configFiles),
            $this->quoteJson($pluginPaths),
            $this->quoteJson($fastdlSettings),
            $this->quote($installCommand),
            $this->quote($updateCommand),
            $this->quoteJson($allowedSwitchFlags),
        ];

        if ($this->hasColumn('game_templates', 'install_resolver')) {
            $columns[] = 'install_resolver';
            $values[] = $this->quoteJson($installResolver);
        }

        if ($this->hasColumn('game_templates', 'supported_os')) {
            $columns[] = 'supported_os';
            $columns[] = 'port_profile';
            $columns[] = 'requirements';
            $values[] = $this->quoteJson(['linux', 'windows']);
            $values[] = $this->quoteJson($this->buildPortProfile($requiredPorts));
            $values[] = $this->quoteJson($this->buildRequirements($gameKey, $steamAppId, $envVars));
        }

        $columns[] = 'created_at';
        $columns[] = 'updated_at';
        $values[] = $this->currentTimestampExpression();
        $values[] = $this->currentTimestampExpression();

        $sql = sprintf(
            'INSERT INTO game_templates (%s) SELECT %s WHERE NOT EXISTS (SELECT 1 FROM game_templates WHERE game_key = %s)',
            implode(', ', $columns),
            implode(', ', $values),
            $this->quote($gameKey),
        );

        $this->addSql($sql);
    }

    private function quote(?string $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        return $this->connection->quote($value);
    }

    private function quoteJson(array $value): string
    {
        return $this->connection->quote($this->jsonEncode($value));
    }

    private function jsonEncode(array $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '[]' : $encoded;
    }

    /**
     * @param array<int, array<string, mixed>> $requiredPorts
     * @return array<int, array<string, mixed>>
     */
    private function buildPortProfile(array $requiredPorts): array
    {
        $roleMap = [
            'game' => 'game',
            'query' => 'query',
            'rcon' => 'rcon',
            'tv' => 'tv',
            'voice' => 'voice',
            'filetransfer' => 'filetransfer',
        ];

        $profile = [];
        foreach ($requiredPorts as $port) {
            if (!is_array($port)) {
                continue;
            }
            $name = strtolower((string) ($port['name'] ?? 'game'));
            $role = $roleMap[$name] ?? $name;
            $protocol = (string) ($port['protocol'] ?? 'udp');
            $count = (int) ($port['count'] ?? 1);
            if ($count <= 0) {
                $count = 1;
            }

            $profile[] = [
                'role' => $role,
                'protocol' => $protocol,
                'count' => $count,
                'required' => isset($port['required']) ? (bool) $port['required'] : true,
                'contiguous' => isset($port['contiguous']) ? (bool) $port['contiguous'] : false,
            ];
        }

        return $profile;
    }

    /**
     * @param array<int, array<string, mixed>> $envVars
     * @return array<string, mixed>
     */
    private function buildRequirements(string $gameKey, ?int $steamAppId, array $envVars): array
    {
        $envVarKeys = $this->extractEnvVarKeys($envVars);

        return [
            'required_vars' => $envVarKeys,
            'required_secrets' => [],
            'steam_install_mode' => $this->resolveSteamInstallMode($gameKey, $steamAppId),
            'customer_allowed_vars' => $envVarKeys,
            'customer_allowed_secrets' => [],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $envVars
     * @return array<int, string>
     */
    private function extractEnvVarKeys(array $envVars): array
    {
        $keys = [];
        foreach ($envVars as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $key = trim((string) ($entry['key'] ?? ''));
            if ($key !== '') {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    private function resolveSteamInstallMode(string $gameKey, ?int $steamAppId): string
    {
        if ($this->isMinecraftNoSteam($gameKey)) {
            return 'none';
        }

        return $steamAppId !== null ? 'anonymous' : 'none';
    }

    private function isMinecraftNoSteam(string $gameKey): bool
    {
        return in_array($gameKey, [
            'minecraft_paper',
            'minecraft_vanilla',
            'minecraft_paper_windows',
            'minecraft_vanilla_windows',
            'minecraft_paper_all',
            'minecraft_vanilla_all',
        ], true);
    }

    private function currentTimestampExpression(): string
    {
        return $this->isSqlite() ? 'CURRENT_TIMESTAMP' : 'CURRENT_TIMESTAMP()';
    }

    private function isSqlite(): bool
    {
        $platform = $this->connection->getDatabasePlatform();

        if (method_exists($platform, 'getName')) {
            return in_array($platform->getName(), ['sqlite', 'sqlite3'], true);
        }

        return $platform instanceof \Doctrine\DBAL\Platforms\SqlitePlatform
            || $platform instanceof \Doctrine\DBAL\Platforms\SQLitePlatform;
    }

    private function hasColumn(string $table, string $column): bool
    {
        $columns = $this->connection->createSchemaManager()->listTableColumns($table);

        return array_key_exists($column, $columns);
    }
}


final class Version20250925090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed classic Easy-Wi templates for legacy and popular games.';
    }

    public function up(Schema $schema): void
    {
        return;
        $this->insertTemplate(
            'ark_windows',
            'ARK: Survival Evolved (Windows)',
            'SteamCMD install with Windows server binary.',
            376030,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                ['name' => 'rcon', 'label' => 'RCON', 'protocol' => 'tcp'],
            ],
            '{{INSTANCE_DIR}}/ShooterGameServer.exe TheIsland?SessionName={{SERVER_NAME}}?Port={{PORT_GAME}}?QueryPort={{PORT_QUERY}}?RCONPort={{PORT_RCON}}?MaxPlayers=70?listen -log',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi ARK'],
            ],
            [
                [
                    'path' => 'ShooterGame/Saved/Config/WindowsServer/GameUserSettings.ini',
                    'description' => 'Server session settings',
                ],
                [
                    'path' => 'ShooterGame/Saved/Config/WindowsServer/Game.ini',
                    'description' => 'Gameplay rules',
                ],
            ],
            [],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 376030 validate +quit',
            'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 376030 +quit',
            []
        );

        $this->insertTemplate(
            'rust_windows',
            'Rust Dedicated Server (Windows)',
            'SteamCMD install with Windows server binary.',
            258550,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                ['name' => 'rcon', 'label' => 'RCON', 'protocol' => 'tcp'],
            ],
            '{{INSTANCE_DIR}}/RustDedicated.exe -batchmode +server.port {{PORT_GAME}} +server.queryport {{PORT_QUERY}} +rcon.port {{PORT_RCON}} +server.hostname "{{SERVER_NAME}}" +rcon.password "{{RCON_PASSWORD}}" +server.maxplayers 50',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Easy-Wi Rust'],
                ['key' => 'RCON_PASSWORD', 'value' => 'change-me'],
            ],
            [
                [
                    'path' => 'server/cfg/server.cfg',
                    'description' => 'Server configuration overrides',
                ],
            ],
            [
                'oxide/plugins',
            ],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 258550 validate +quit',
            'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 258550 +quit',
            ['+server.level', '+server.seed']
        );

        $this->insertTemplate(
            'enshrouded',
            'Enshrouded Dedicated Server',
            'SteamCMD install with Linux server binary.',
            2278520,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
            ],
            '{{INSTANCE_DIR}}/enshrouded_server -log',
            [],
            [
                [
                    'path' => 'enshrouded_server.json',
                    'description' => 'Server configuration',
                ],
            ],
            [],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 2278520 validate +quit',
            'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 2278520 +quit',
            []
        );

        $this->insertTemplate(
            'squad',
            'Squad Dedicated Server',
            'SteamCMD install with default server config.',
            403240,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                ['name' => 'rcon', 'label' => 'RCON', 'protocol' => 'tcp'],
            ],
            '{{INSTANCE_DIR}}/SquadGameServer.sh Port={{PORT_GAME}} QueryPort={{PORT_QUERY}} RCONPort={{PORT_RCON}} -log -MaxPlayers={{MAX_PLAYERS}}',
            [
                ['key' => 'MAX_PLAYERS', 'value' => '80'],
            ],
            [
                [
                    'path' => 'SquadGame/ServerConfig/Server.cfg',
                    'description' => 'Server configuration',
                ],
            ],
            [],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 403240 validate +quit',
            'steamcmd +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 403240 +quit',
            []
        );

        $this->insertTemplate(
            'squad_windows',
            'Squad Dedicated Server (Windows)',
            'SteamCMD install with Windows server binary.',
            403240,
            'steam',
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'udp'],
                ['name' => 'rcon', 'label' => 'RCON', 'protocol' => 'tcp'],
            ],
            '{{INSTANCE_DIR}}/SquadGameServer.exe Port={{PORT_GAME}} QueryPort={{PORT_QUERY}} RCONPort={{PORT_RCON}} -log -MaxPlayers={{MAX_PLAYERS}}',
            [
                ['key' => 'MAX_PLAYERS', 'value' => '80'],
            ],
            [
                [
                    'path' => 'SquadGame/ServerConfig/Server.cfg',
                    'description' => 'Server configuration',
                ],
            ],
            [],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 403240 validate +quit',
            'steamcmd.exe +force_install_dir {{INSTANCE_DIR}} +login {{STEAM_LOGIN}} +app_update 403240 +quit',
            []
        );

        $this->insertTemplate(
            'fivem',
            'FiveM (FXServer)',
            'Download latest FXServer artifacts and run server.cfg.',
            null,
            null,
            [
                ['name' => 'game_udp', 'label' => 'Game (UDP)', 'protocol' => 'udp'],
                ['name' => 'game_tcp', 'label' => 'Game (TCP)', 'protocol' => 'tcp'],
            ],
            '{{INSTANCE_DIR}}/run.sh +exec server.cfg',
            [],
            [
                [
                    'path' => 'server.cfg',
                    'description' => 'Base FXServer configuration',
                    'contents' => "endpoint_add_tcp \"0.0.0.0:{{PORT_GAME_TCP}}\"\nendpoint_add_udp \"0.0.0.0:{{PORT_GAME_UDP}}\"\nsv_hostname \"Easy-Wi FiveM\"\n",
                ],
            ],
            [],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'cd {{INSTANCE_DIR}} && mkdir -p ./ && curl -sSL https://runtime.fivem.net/artifacts/fivem/build_proot_linux/master/ | grep -oP \'href="[^"]+fx.tar.xz"\' | tail -1 | cut -d\'"\' -f2 | xargs -I {} curl -L -o fx.tar.xz https://runtime.fivem.net/artifacts/fivem/build_proot_linux/master/{} && tar -xf fx.tar.xz && chmod +x run.sh',
            'cd {{INSTANCE_DIR}} && mkdir -p ./ && curl -sSL https://runtime.fivem.net/artifacts/fivem/build_proot_linux/master/ | grep -oP \'href="[^"]+fx.tar.xz"\' | tail -1 | cut -d\'"\' -f2 | xargs -I {} curl -L -o fx.tar.xz https://runtime.fivem.net/artifacts/fivem/build_proot_linux/master/{} && tar -xf fx.tar.xz && chmod +x run.sh',
            []
        );

        $this->insertTemplate(
            'fivem_windows',
            'FiveM (FXServer) (Windows)',
            'Download latest FXServer Windows artifacts and run server.cfg.',
            null,
            null,
            [
                ['name' => 'game_udp', 'label' => 'Game (UDP)', 'protocol' => 'udp'],
                ['name' => 'game_tcp', 'label' => 'Game (TCP)', 'protocol' => 'tcp'],
            ],
            '{{INSTANCE_DIR}}/FXServer.exe +exec server.cfg',
            [],
            [
                [
                    'path' => 'server.cfg',
                    'description' => 'Base FXServer configuration',
                    'contents' => "endpoint_add_tcp \"0.0.0.0:{{PORT_GAME_TCP}}\"\nendpoint_add_udp \"0.0.0.0:{{PORT_GAME_UDP}}\"\nsv_hostname \"Easy-Wi FiveM\"\n",
                ],
            ],
            [],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'powershell -Command "$ProgressPreference = \'SilentlyContinue\'; $page = Invoke-WebRequest -UseBasicParsing https://runtime.fivem.net/artifacts/fivem/build_server_windows/master/; $match = ($page.Links | Where-Object href -match \'server.zip\' | Select-Object -Last 1).href; Invoke-WebRequest -UseBasicParsing -OutFile server.zip (\"https://runtime.fivem.net/artifacts/fivem/build_server_windows/master/$match\"); Expand-Archive -Force server.zip ."',
            'powershell -Command "$ProgressPreference = \'SilentlyContinue\'; $page = Invoke-WebRequest -UseBasicParsing https://runtime.fivem.net/artifacts/fivem/build_server_windows/master/; $match = ($page.Links | Where-Object href -match \'server.zip\' | Select-Object -Last 1).href; Invoke-WebRequest -UseBasicParsing -OutFile server.zip (\"https://runtime.fivem.net/artifacts/fivem/build_server_windows/master/$match\"); Expand-Archive -Force server.zip ."',
            []
        );

        $this->insertTemplate(
            'teamspeak3',
            'TeamSpeak 3 Server',
            'Download and run TeamSpeak 3 server binaries.',
            null,
            null,
            [
                ['name' => 'voice', 'label' => 'Voice', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'tcp'],
                ['name' => 'filetransfer', 'label' => 'File Transfer', 'protocol' => 'tcp'],
            ],
            './ts3server_minimal_runscript.sh default_voice_port={{PORT_VOICE}} query_port={{PORT_QUERY}} filetransfer_port={{PORT_FILETRANSFER}} serveradmin_password={{SERVER_ADMIN_PASSWORD}}',
            [
                ['key' => 'SERVER_ADMIN_PASSWORD', 'value' => 'change-me'],
            ],
            [],
            [],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'cd {{INSTANCE_DIR}} && curl -L -o ts3.tar.bz2 https://files.teamspeak-services.com/releases/server/3.13.7/teamspeak3-server_linux_amd64-3.13.7.tar.bz2 && tar -xjf ts3.tar.bz2 --strip-components=1',
            'cd {{INSTANCE_DIR}} && curl -L -o ts3.tar.bz2 https://files.teamspeak-services.com/releases/server/3.13.7/teamspeak3-server_linux_amd64-3.13.7.tar.bz2 && tar -xjf ts3.tar.bz2 --strip-components=1',
            []
        );

        $this->insertTemplate(
            'teamspeak3_windows',
            'TeamSpeak 3 Server (Windows)',
            'Download and run TeamSpeak 3 Windows binaries.',
            null,
            null,
            [
                ['name' => 'voice', 'label' => 'Voice', 'protocol' => 'udp'],
                ['name' => 'query', 'label' => 'Query', 'protocol' => 'tcp'],
                ['name' => 'filetransfer', 'label' => 'File Transfer', 'protocol' => 'tcp'],
            ],
            '{{INSTANCE_DIR}}/ts3server.exe default_voice_port={{PORT_VOICE}} query_port={{PORT_QUERY}} filetransfer_port={{PORT_FILETRANSFER}} serveradmin_password={{SERVER_ADMIN_PASSWORD}}',
            [
                ['key' => 'SERVER_ADMIN_PASSWORD', 'value' => 'change-me'],
            ],
            [],
            [],
            [
                'enabled' => false,
                'base_url' => '',
                'root_path' => '',
            ],
            'powershell -Command "$ProgressPreference = \'SilentlyContinue\'; Invoke-WebRequest -UseBasicParsing -OutFile ts3.zip https://files.teamspeak-services.com/releases/server/3.13.7/teamspeak3-server_win64-3.13.7.zip; Expand-Archive -Force ts3.zip ."',
            'powershell -Command "$ProgressPreference = \'SilentlyContinue\'; Invoke-WebRequest -UseBasicParsing -OutFile ts3.zip https://files.teamspeak-services.com/releases/server/3.13.7/teamspeak3-server_win64-3.13.7.zip; Expand-Archive -Force ts3.zip ."',
            []
        );
    }

    public function down(Schema $schema): void
    {
        return;
        $this->deleteTemplate('ark_windows');
        $this->deleteTemplate('rust_windows');
        $this->deleteTemplate('enshrouded');
        $this->deleteTemplate('squad');
        $this->deleteTemplate('squad_windows');
        $this->deleteTemplate('fivem');
        $this->deleteTemplate('fivem_windows');
        $this->deleteTemplate('teamspeak3');
        $this->deleteTemplate('teamspeak3_windows');
    }

    private function insertTemplate(
        string $gameKey,
        string $displayName,
        ?string $description,
        ?int $steamAppId,
        ?string $sniperProfile,
        array $requiredPorts,
        string $startParams,
        array $envVars,
        array $configFiles,
        array $pluginPaths,
        array $fastdlSettings,
        string $installCommand,
        string $updateCommand,
        array $allowedSwitchFlags,
    ): void {
        $columns = [
            'game_key',
            'display_name',
            'description',
            'steam_app_id',
            'sniper_profile',
            'required_ports',
            'start_params',
            'env_vars',
            'config_files',
            'plugin_paths',
            'fastdl_settings',
            'install_command',
            'update_command',
            'allowed_switch_flags',
        ];

        $values = [
            $this->quote($gameKey),
            $this->quote($displayName),
            $this->quote($description),
            $steamAppId === null ? 'NULL' : (string) $steamAppId,
            $this->quote($sniperProfile),
            $this->quoteJson($requiredPorts),
            $this->quote($startParams),
            $this->quoteJson($envVars),
            $this->quoteJson($configFiles),
            $this->quoteJson($pluginPaths),
            $this->quoteJson($fastdlSettings),
            $this->quote($installCommand),
            $this->quote($updateCommand),
            $this->quoteJson($allowedSwitchFlags),
        ];

        if ($this->hasColumn('game_templates', 'supported_os')) {
            $columns[] = 'supported_os';
            $columns[] = 'port_profile';
            $columns[] = 'requirements';
            $values[] = $this->quoteJson($this->resolveSupportedOs($gameKey));
            $values[] = $this->quoteJson($this->buildPortProfile($requiredPorts));
            $values[] = $this->quoteJson($this->buildRequirements($gameKey, $steamAppId, $envVars));
        }

        $columns[] = 'created_at';
        $columns[] = 'updated_at';
        $values[] = $this->currentTimestampExpression();
        $values[] = $this->currentTimestampExpression();

        $sql = sprintf(
            'INSERT INTO game_templates (%s) SELECT %s WHERE NOT EXISTS (SELECT 1 FROM game_templates WHERE game_key = %s)',
            implode(', ', $columns),
            implode(', ', $values),
            $this->quote($gameKey),
        );

        $this->addSql($sql);
    }

    private function deleteTemplate(string $gameKey): void
    {
        $this->addSql(sprintf(
            'DELETE FROM game_templates WHERE game_key = %s',
            $this->quote($gameKey),
        ));
    }

    private function quote(?string $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        return $this->connection->quote($value);
    }

    private function quoteJson(array $value): string
    {
        return $this->connection->quote($this->jsonEncode($value));
    }

    private function jsonEncode(array $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '[]' : $encoded;
    }

    /**
     * @param array<int, array<string, mixed>> $requiredPorts
     * @return array<int, array<string, mixed>>
     */
    private function buildPortProfile(array $requiredPorts): array
    {
        $roleMap = [
            'game' => 'game',
            'query' => 'query',
            'rcon' => 'rcon',
            'tv' => 'tv',
            'voice' => 'voice',
            'filetransfer' => 'filetransfer',
        ];

        $profile = [];
        foreach ($requiredPorts as $port) {
            if (!is_array($port)) {
                continue;
            }
            $name = strtolower((string) ($port['name'] ?? 'game'));
            $role = $roleMap[$name] ?? $name;
            $protocol = (string) ($port['protocol'] ?? 'udp');
            $count = (int) ($port['count'] ?? 1);
            if ($count <= 0) {
                $count = 1;
            }

            $profile[] = [
                'role' => $role,
                'protocol' => $protocol,
                'count' => $count,
                'required' => isset($port['required']) ? (bool) $port['required'] : true,
                'contiguous' => isset($port['contiguous']) ? (bool) $port['contiguous'] : false,
            ];
        }

        return $profile;
    }

    /**
     * @param array<int, array<string, mixed>> $envVars
     * @return array<string, mixed>
     */
    private function buildRequirements(string $gameKey, ?int $steamAppId, array $envVars): array
    {
        $envVarKeys = $this->extractEnvVarKeys($envVars);
        $requiredSecrets = $this->isCsTemplate($gameKey) ? ['STEAM_GSLT'] : [];

        return [
            'required_vars' => $envVarKeys,
            'required_secrets' => $requiredSecrets,
            'steam_install_mode' => $this->resolveSteamInstallMode($gameKey, $steamAppId),
            'customer_allowed_vars' => $envVarKeys,
            'customer_allowed_secrets' => $requiredSecrets,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $envVars
     * @return array<int, string>
     */
    private function extractEnvVarKeys(array $envVars): array
    {
        $keys = [];
        foreach ($envVars as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $key = trim((string) ($entry['key'] ?? ''));
            if ($key !== '') {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @return array<int, string>
     */
    private function resolveSupportedOs(string $gameKey): array
    {
        return str_ends_with($gameKey, '_windows') ? ['windows'] : ['linux'];
    }

    private function resolveSteamInstallMode(string $gameKey, ?int $steamAppId): string
    {
        if ($this->isMinecraftNoSteam($gameKey)) {
            return 'none';
        }

        return $steamAppId !== null ? 'anonymous' : 'none';
    }

    private function isMinecraftNoSteam(string $gameKey): bool
    {
        return in_array($gameKey, [
            'minecraft_paper',
            'minecraft_vanilla',
            'minecraft_paper_windows',
            'minecraft_vanilla_windows',
        ], true);
    }

    private function isCsTemplate(string $gameKey): bool
    {
        return in_array($gameKey, [
            'cs2',
            'csgo_legacy',
            'cs2_windows',
            'csgo_legacy_windows',
        ], true);
    }

    private function currentTimestampExpression(): string
    {
        return $this->isSqlite() ? 'CURRENT_TIMESTAMP' : 'CURRENT_TIMESTAMP()';
    }

    private function isSqlite(): bool
    {
        $platform = $this->connection->getDatabasePlatform();

        if (method_exists($platform, 'getName')) {
            return in_array($platform->getName(), ['sqlite', 'sqlite3'], true);
        }

        return $platform instanceof \Doctrine\DBAL\Platforms\SqlitePlatform
            || $platform instanceof \Doctrine\DBAL\Platforms\SQLitePlatform;
    }

    private function hasColumn(string $table, string $column): bool
    {
        $columns = $this->connection->createSchemaManager()->listTableColumns($table);

        return array_key_exists($column, $columns);
    }
}


final class Version20251001120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tag OS-specific templates with merged_group in requirements.';
    }

    public function up(Schema $schema): void
    {
        if (!$this->hasColumn('game_templates', 'requirements')) {
            return;
        }

        $templates = $this->connection->fetchAllAssociative(
            'SELECT id, game_key, steam_app_id, requirements, supported_os FROM game_templates'
        );

        $groups = [];
        foreach ($templates as $template) {
            $gameKey = (string) $template['game_key'];
            $steamAppId = $template['steam_app_id'] !== null ? (int) $template['steam_app_id'] : null;
            $os = $this->resolveTemplateOs($gameKey, (string) ($template['supported_os'] ?? '[]'));
            if ($os === null) {
                continue;
            }
            $baseKey = $this->resolveBaseGameKey($gameKey);
            $groupKey = sprintf('%s:%s', $steamAppId ?? 'null', $baseKey);
            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'base_key' => $baseKey,
                    'templates' => [],
                    'os' => [],
                ];
            }
            $groups[$groupKey]['templates'][] = $template;
            $groups[$groupKey]['os'][$os] = true;
        }

        foreach ($groups as $group) {
            if (count($group['templates']) < 2 || count($group['os']) < 2) {
                continue;
            }
            $baseKey = $group['base_key'];
            foreach ($group['templates'] as $template) {
                $requirements = $this->decodeJsonObject((string) $template['requirements']);
                $requirements['merged_group'] = $baseKey;
                $this->addSql(sprintf(
                    'UPDATE game_templates SET requirements = %s WHERE id = %d',
                    $this->quoteJson($requirements),
                    (int) $template['id'],
                ));
            }
        }
    }

    public function down(Schema $schema): void
    {
        if (!$this->hasColumn('game_templates', 'requirements')) {
            return;
        }

        $templates = $this->connection->fetchAllAssociative(
            'SELECT id, requirements FROM game_templates'
        );

        foreach ($templates as $template) {
            $requirements = $this->decodeJsonObject((string) $template['requirements']);
            if (!array_key_exists('merged_group', $requirements)) {
                continue;
            }
            unset($requirements['merged_group']);
            $this->addSql(sprintf(
                'UPDATE game_templates SET requirements = %s WHERE id = %d',
                $this->quoteJson($requirements),
                (int) $template['id'],
            ));
        }
    }

    private function resolveBaseGameKey(string $gameKey): string
    {
        return preg_replace('/_(windows|linux)$/', '', $gameKey) ?? $gameKey;
    }

    private function resolveTemplateOs(string $gameKey, string $supportedOsJson): ?string
    {
        $supportedOs = $this->decodeJsonArray($supportedOsJson);
        if (count($supportedOs) === 1) {
            $value = strtolower((string) $supportedOs[0]);
            if (in_array($value, ['linux', 'windows'], true)) {
                return $value;
            }
        }

        if (str_ends_with($gameKey, '_windows')) {
            return 'windows';
        }
        if (str_ends_with($gameKey, '_linux')) {
            return 'linux';
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function decodeJsonArray(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonObject(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function quoteJson(array $value): string
    {
        return $this->connection->quote($this->jsonEncode($value));
    }

    private function jsonEncode(array $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '{}' : $encoded;
    }

    private function hasColumn(string $table, string $column): bool
    {
        $columns = $this->connection->createSchemaManager()->listTableColumns($table);

        return array_key_exists($column, $columns);
    }
}


final class Version20260117112000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add service API settings to agents.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE agents ADD service_base_url VARCHAR(255) DEFAULT NULL, ADD service_api_token_encrypted LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE agents DROP service_base_url, DROP service_api_token_encrypted');
    }
}


final class Version20260117113000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store instance start script path.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE instances ADD start_script_path VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE instances DROP start_script_path');
    }
}


final class Version20260117114000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Steam account, GSLT key, and server name to instances.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE instances ADD steam_account VARCHAR(255) DEFAULT NULL, ADD gsl_key VARCHAR(255) DEFAULT NULL, ADD server_name VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE instances DROP steam_account, DROP gsl_key, DROP server_name');
    }
}


final class Version20260117115000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add slots and assigned port to instances.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE instances ADD slots INT NOT NULL DEFAULT 16, ADD assigned_port INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE instances DROP slots, DROP assigned_port');
    }
}


final class Version20260117121000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add domain, DDoS protection flag, and assigned port to webspaces.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('webspaces')) {
            return;
        }

        $this->addSql("ALTER TABLE webspaces ADD domain VARCHAR(255) NOT NULL DEFAULT ''");
        $this->addSql('ALTER TABLE webspaces ADD ddos_protection_enabled TINYINT(1) NOT NULL DEFAULT 1');
        $this->addSql('ALTER TABLE webspaces ADD assigned_port INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('webspaces')) {
            return;
        }

        $this->addSql('ALTER TABLE webspaces DROP domain');
        $this->addSql('ALTER TABLE webspaces DROP ddos_protection_enabled');
        $this->addSql('ALTER TABLE webspaces DROP assigned_port');
    }
}


final class Version20260118120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add agent jobs and node references for TS/Sinusbot nodes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE agent_jobs (id VARCHAR(36) NOT NULL, node_id VARCHAR(64) NOT NULL, type VARCHAR(120) NOT NULL, payload JSON NOT NULL, status VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, started_at DATETIME DEFAULT NULL, finished_at DATETIME DEFAULT NULL, log_text LONGTEXT DEFAULT NULL, error_text LONGTEXT DEFAULT NULL, retries INT NOT NULL, idempotency_key VARCHAR(64) DEFAULT NULL, result_payload JSON DEFAULT NULL, INDEX idx_agent_jobs_node_status (node_id, status), INDEX idx_agent_jobs_idempotency (idempotency_key), INDEX IDX_2789AA3C5C1662B (node_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE agent_jobs ADD CONSTRAINT FK_2789AA3C5C1662B FOREIGN KEY (node_id) REFERENCES agents (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE ts3_nodes ADD agent_id VARCHAR(64) NOT NULL');
        $this->addSql('ALTER TABLE ts6_nodes ADD agent_id VARCHAR(64) NOT NULL');
        $this->addSql('ALTER TABLE sinusbot_nodes ADD agent_id VARCHAR(64) NOT NULL');
        $this->addSql('ALTER TABLE ts3_nodes ADD CONSTRAINT FK_TS3_NODES_AGENT FOREIGN KEY (agent_id) REFERENCES agents (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ts6_nodes ADD CONSTRAINT FK_TS6_NODES_AGENT FOREIGN KEY (agent_id) REFERENCES agents (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE sinusbot_nodes ADD CONSTRAINT FK_SINUSBOT_NODES_AGENT FOREIGN KEY (agent_id) REFERENCES agents (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ts3_nodes DROP FOREIGN KEY FK_TS3_NODES_AGENT');
        $this->addSql('ALTER TABLE ts6_nodes DROP FOREIGN KEY FK_TS6_NODES_AGENT');
        $this->addSql('ALTER TABLE sinusbot_nodes DROP FOREIGN KEY FK_SINUSBOT_NODES_AGENT');
        $this->addSql('ALTER TABLE ts3_nodes DROP agent_id');
        $this->addSql('ALTER TABLE ts6_nodes DROP agent_id');
        $this->addSql('ALTER TABLE sinusbot_nodes DROP agent_id');

        $this->addSql('DROP TABLE agent_jobs');
    }
}


final class Version20260201090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add webspace management settings (PHP settings, cron tasks, git repository).';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('webspaces')) {
            return;
        }

        $this->addSql('ALTER TABLE webspaces ADD php_settings JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE webspaces ADD cron_tasks LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE webspaces ADD git_repo_url VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE webspaces ADD git_branch VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('webspaces')) {
            return;
        }

        $this->addSql('ALTER TABLE webspaces DROP php_settings');
        $this->addSql('ALTER TABLE webspaces DROP cron_tasks');
        $this->addSql('ALTER TABLE webspaces DROP git_repo_url');
        $this->addSql('ALTER TABLE webspaces DROP git_branch');
    }
}


final class Version20260119120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename server_sftp_access keys column to ssh_keys.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('server_sftp_access')) {
            return;
        }

        $table = $schema->getTable('server_sftp_access');
        if (!$table->hasColumn('keys') || $table->hasColumn('ssh_keys')) {
            return;
        }

        $this->addSql('ALTER TABLE server_sftp_access CHANGE `keys` ssh_keys JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('server_sftp_access')) {
            return;
        }

        $table = $schema->getTable('server_sftp_access');
        if (!$table->hasColumn('ssh_keys') || $table->hasColumn('keys')) {
            return;
        }

        $this->addSql('ALTER TABLE server_sftp_access CHANGE ssh_keys `keys` JSON NOT NULL');
    }
}


final class Version20260304120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add default filetransfer port to TS3 nodes and default voice port to TS6 nodes.';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('ts3_nodes')) {
            $table = $schema->getTable('ts3_nodes');
            if (!$table->hasColumn('filetransfer_port')) {
                $this->addSql('ALTER TABLE ts3_nodes ADD filetransfer_port INT NOT NULL DEFAULT 30033');
            }
        }

        if ($schema->hasTable('ts6_nodes')) {
            $table = $schema->getTable('ts6_nodes');
            if (!$table->hasColumn('voice_port')) {
                $this->addSql('ALTER TABLE ts6_nodes ADD voice_port INT NOT NULL DEFAULT 9987');
            }
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('ts3_nodes')) {
            $table = $schema->getTable('ts3_nodes');
            if ($table->hasColumn('filetransfer_port')) {
                $this->addSql('ALTER TABLE ts3_nodes DROP COLUMN filetransfer_port');
            }
        }

        if ($schema->hasTable('ts6_nodes')) {
            $table = $schema->getTable('ts6_nodes');
            if ($table->hasColumn('voice_port')) {
                $this->addSql('ALTER TABLE ts6_nodes DROP COLUMN voice_port');
            }
        }
    }
}

final class Version20260318120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add admin SSH public key to users.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('users')) {
            return;
        }

        $table = $schema->getTable('users');
        if ($table->hasColumn('admin_ssh_public_key')) {
            return;
        }

        $this->addSql('ALTER TABLE users ADD admin_ssh_public_key LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('users')) {
            return;
        }

        $table = $schema->getTable('users');
        if (!$table->hasColumn('admin_ssh_public_key')) {
            return;
        }

        $this->addSql('ALTER TABLE users DROP admin_ssh_public_key');
    }
}

final class Version20260318121000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add admin SSH key enablement flag.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('users')) {
            return;
        }

        $table = $schema->getTable('users');
        if ($table->hasColumn('admin_ssh_key_enabled')) {
            return;
        }

        $this->addSql('ALTER TABLE users ADD admin_ssh_key_enabled TINYINT(1) NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('users')) {
            return;
        }

        $table = $schema->getTable('users');
        if (!$table->hasColumn('admin_ssh_key_enabled')) {
            return;
        }

        $this->addSql('ALTER TABLE users DROP admin_ssh_key_enabled');
    }
}

final class Version20260320130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add pending admin SSH public key storage.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('users')) {
            return;
        }

        $table = $schema->getTable('users');
        if ($table->hasColumn('admin_ssh_public_key_pending')) {
            return;
        }

        $this->addSql('ALTER TABLE users ADD admin_ssh_public_key_pending LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('users')) {
            return;
        }

        $table = $schema->getTable('users');
        if (!$table->hasColumn('admin_ssh_public_key_pending')) {
            return;
        }

        $this->addSql('ALTER TABLE users DROP admin_ssh_public_key_pending');
    }
}

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

final class Version20260323110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Raise agent job concurrency defaults to 50.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('agents')) {
            return;
        }

        $table = $schema->getTable('agents');
        if (!$table->hasColumn('job_concurrency')) {
            return;
        }

        $this->addSql('ALTER TABLE agents ALTER job_concurrency SET DEFAULT 50');
        $this->addSql('UPDATE agents SET job_concurrency = 50 WHERE job_concurrency < 50');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('agents')) {
            return;
        }

        $table = $schema->getTable('agents');
        if (!$table->hasColumn('job_concurrency')) {
            return;
        }

        $this->addSql('ALTER TABLE agents ALTER job_concurrency SET DEFAULT 1');
    }
}

final class Version20260412120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add per-customer database limits.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('users')) {
            return;
        }

        $table = $schema->getTable('users');
        if ($table->hasColumn('database_limit')) {
            return;
        }

        $this->addSql('ALTER TABLE users ADD database_limit INT NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('users')) {
            return;
        }

        $table = $schema->getTable('users');
        if ($table->hasColumn('database_limit')) {
            $this->addSql('ALTER TABLE users DROP database_limit');
        }
    }
}

final class Version20260601120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Update CS2 start command and add MAX_PLAYERS env var.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('game_templates')) {
            return;
        }

        $this->addSql(sprintf(
            'UPDATE game_templates SET start_params = %s WHERE game_key = %s',
            $this->quote('/home/installdir/game/cs2.sh -port {{PORT_GAME}} +sv_queryport {{PORT_QUERY}} +rcon_port {{PORT_RCON}} +tv_port {{PORT_TV}} +maxplayers {{MAX_PLAYERS}} +map de_dust2 +sv_setsteamaccount {{STEAM_GSLT}} +hostname "{{SERVER_NAME}}" +rcon_password "{{RCON_PASSWORD}}"'),
            $this->quote('cs2'),
        ));

        $templates = $this->connection->fetchAllAssociative('SELECT id, env_vars FROM game_templates WHERE game_key = ' . $this->quote('cs2'));
        foreach ($templates as $template) {
            $envVars = $this->decodeJsonArray((string) ($template['env_vars'] ?? '[]'));
            $hasMaxPlayers = false;
            foreach ($envVars as $entry) {
                if (is_array($entry) && (string) ($entry['key'] ?? '') === 'MAX_PLAYERS') {
                    $hasMaxPlayers = true;
                    break;
                }
            }
            if (!$hasMaxPlayers) {
                $envVars[] = ['key' => 'MAX_PLAYERS', 'value' => '16'];
                $this->addSql(sprintf(
                    'UPDATE game_templates SET env_vars = %s WHERE id = %d',
                    $this->quoteJson($envVars),
                    (int) $template['id'],
                ));
            }
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('game_templates')) {
            return;
        }

        $this->addSql(sprintf(
            'UPDATE game_templates SET start_params = %s WHERE game_key = %s',
            $this->quote('{{INSTANCE_DIR}}/game/bin/linuxsteamrt64/cs2 -dedicated -console -usercon -tickrate 128 +map de_dust2 +sv_setsteamaccount {{STEAM_GSLT}} +hostname "{{SERVER_NAME}}" +rcon_password "{{RCON_PASSWORD}}"'),
            $this->quote('cs2'),
        ));

        $templates = $this->connection->fetchAllAssociative('SELECT id, env_vars FROM game_templates WHERE game_key = ' . $this->quote('cs2'));
        foreach ($templates as $template) {
            $envVars = $this->decodeJsonArray((string) ($template['env_vars'] ?? '[]'));
            $filtered = [];
            foreach ($envVars as $entry) {
                if (is_array($entry) && (string) ($entry['key'] ?? '') === 'MAX_PLAYERS') {
                    continue;
                }
                $filtered[] = $entry;
            }
            $this->addSql(sprintf(
                'UPDATE game_templates SET env_vars = %s WHERE id = %d',
                $this->quoteJson($filtered),
                (int) $template['id'],
            ));
        }
    }

    private function decodeJsonArray(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function quote(?string $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        return $this->connection->quote($value);
    }

    private function quoteJson(array $value): string
    {
        return $this->connection->quote($this->jsonEncode($value));
    }

    private function jsonEncode(array $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '[]' : $encoded;
    }
}

final class Version20260612120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cascade deletes for TS6 virtual server relationships.';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('ts6_virtual_servers')) {
            $this->dropForeignKey('ts6_virtual_servers', 'node_id', 'ts6_nodes');
            $this->addSql('ALTER TABLE ts6_virtual_servers ADD CONSTRAINT FK_TS6_VIRTUAL_SERVERS_NODE FOREIGN KEY (node_id) REFERENCES ts6_nodes (id) ON DELETE CASCADE');
        }

        if ($schema->hasTable('ts6_tokens')) {
            $this->dropForeignKey('ts6_tokens', 'virtual_server_id', 'ts6_virtual_servers');
            $this->addSql('ALTER TABLE ts6_tokens ADD CONSTRAINT FK_TS6_TOKENS_SERVER FOREIGN KEY (virtual_server_id) REFERENCES ts6_virtual_servers (id) ON DELETE CASCADE');
        }

        if ($schema->hasTable('ts6_viewers')) {
            $this->dropForeignKey('ts6_viewers', 'virtual_server_id', 'ts6_virtual_servers');
            $this->addSql('ALTER TABLE ts6_viewers ADD CONSTRAINT FK_TS6_VIEWERS_SERVER FOREIGN KEY (virtual_server_id) REFERENCES ts6_virtual_servers (id) ON DELETE CASCADE');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('ts6_viewers')) {
            $this->dropForeignKey('ts6_viewers', 'virtual_server_id', 'ts6_virtual_servers');
            $this->addSql('ALTER TABLE ts6_viewers ADD CONSTRAINT FK_TS6_VIEWERS_SERVER FOREIGN KEY (virtual_server_id) REFERENCES ts6_virtual_servers (id)');
        }

        if ($schema->hasTable('ts6_tokens')) {
            $this->dropForeignKey('ts6_tokens', 'virtual_server_id', 'ts6_virtual_servers');
            $this->addSql('ALTER TABLE ts6_tokens ADD CONSTRAINT FK_TS6_TOKENS_SERVER FOREIGN KEY (virtual_server_id) REFERENCES ts6_virtual_servers (id)');
        }

        if ($schema->hasTable('ts6_virtual_servers')) {
            $this->dropForeignKey('ts6_virtual_servers', 'node_id', 'ts6_nodes');
            $this->addSql('ALTER TABLE ts6_virtual_servers ADD CONSTRAINT FK_TS6_VIRTUAL_SERVERS_NODE FOREIGN KEY (node_id) REFERENCES ts6_nodes (id)');
        }
    }

    private function dropForeignKey(string $table, string $column, string $referencedTable): void
    {
        $constraint = $this->connection->fetchOne(
            'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME = ?',
            [$table, $column, $referencedTable],
        );

        if (is_string($constraint) && $constraint !== '') {
            $this->addSql(sprintf('ALTER TABLE %s DROP FOREIGN KEY %s', $table, $constraint));
        }
    }
}

final class Version20250318100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add optional customer assignment to Sinusbot nodes.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('sinusbot_nodes')) {
            return;
        }

        $table = $schema->getTable('sinusbot_nodes');
        if ($table->hasColumn('customer_id')) {
            return;
        }

        $this->addSql('ALTER TABLE sinusbot_nodes ADD customer_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_sinusbot_nodes_customer_id ON sinusbot_nodes (customer_id)');
        $this->addSql('ALTER TABLE sinusbot_nodes ADD CONSTRAINT fk_sinusbot_nodes_customer FOREIGN KEY (customer_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('sinusbot_nodes')) {
            return;
        }

        $table = $schema->getTable('sinusbot_nodes');
        if (!$table->hasColumn('customer_id')) {
            return;
        }

        $this->dropForeignKey('sinusbot_nodes', 'customer_id', 'users');
        $this->addSql('DROP INDEX idx_sinusbot_nodes_customer_id ON sinusbot_nodes');
        $this->addSql('ALTER TABLE sinusbot_nodes DROP customer_id');
    }

    private function dropForeignKey(string $table, string $column, string $referencedTable): void
    {
        $constraint = $this->connection->fetchOne(
            'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME = ?',
            [$table, $column, $referencedTable],
        );

        if (is_string($constraint) && $constraint !== '') {
            $this->addSql(sprintf('ALTER TABLE %s DROP FOREIGN KEY %s', $table, $constraint));
        }
    }
}
