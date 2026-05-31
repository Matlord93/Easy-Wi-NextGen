<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;
final class Version20250310121000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add visibility flags for public site and customer marketplace shop products.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('shop_products')) {
            return;
        }

        $table = $schema->getTable('shop_products');
        if (!$table->hasColumn('is_public_active')) {
            $this->addSql('ALTER TABLE shop_products ADD is_public_active TINYINT(1) NOT NULL DEFAULT 1');
        }

        if (!$table->hasColumn('is_customer_active')) {
            $this->addSql('ALTER TABLE shop_products ADD is_customer_active TINYINT(1) NOT NULL DEFAULT 1');
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('shop_products')) {
            return;
        }

        $table = $schema->getTable('shop_products');
        if ($table->hasColumn('is_public_active')) {
            $this->addSql('ALTER TABLE shop_products DROP is_public_active');
        }

        if ($table->hasColumn('is_customer_active')) {
            $this->addSql('ALTER TABLE shop_products DROP is_customer_active');
        }
    }
}
