<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
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
