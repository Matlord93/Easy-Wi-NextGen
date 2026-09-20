<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260920000000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add shop product variants and coupons'; }
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE shop_product_variants (id INT AUTO_INCREMENT NOT NULL, product_id INT NOT NULL, sku VARCHAR(80) NOT NULL, name VARCHAR(120) NOT NULL, price_monthly_cents INT NOT NULL, cpu_limit INT NOT NULL, ram_limit INT NOT NULL, disk_limit INT NOT NULL, active TINYINT(1) NOT NULL, UNIQUE INDEX uniq_shop_variant_sku (sku), INDEX IDX_SHOP_VARIANT_PRODUCT (product_id), PRIMARY KEY(id), CONSTRAINT FK_SHOP_VARIANT_PRODUCT FOREIGN KEY (product_id) REFERENCES shop_products (id) ON DELETE CASCADE) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE shop_coupons (id INT AUTO_INCREMENT NOT NULL, site_id INT NOT NULL, code VARCHAR(40) NOT NULL, discount_type VARCHAR(10) NOT NULL, discount_value INT NOT NULL, minimum_cents INT NOT NULL, maximum_uses INT DEFAULT NULL, used_count INT NOT NULL, valid_from DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', valid_until DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', active TINYINT(1) NOT NULL, UNIQUE INDEX uniq_shop_coupon_site_code (site_id, code), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE shop_product_variants');
        $this->addSql('DROP TABLE shop_coupons');
    }
}
