<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260920030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align relation index names and coupon date columns with Doctrine metadata';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE access_role_assignments RENAME INDEX idx_access_assign_role TO IDX_1369EF5CD60322AC');
        $this->addSql('ALTER TABLE access_role_assignments RENAME INDEX idx_access_assign_user TO IDX_1369EF5CA76ED395');
        $this->addSql('ALTER TABLE shop_coupons CHANGE valid_from valid_from DATETIME DEFAULT NULL, CHANGE valid_until valid_until DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE notification_preferences RENAME INDEX idx_notification_pref_recipient TO IDX_3CAA95B4E92F8F78');
        $this->addSql('ALTER TABLE shop_product_variants RENAME INDEX idx_shop_variant_product TO IDX_59753EC74584665A');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE access_role_assignments RENAME INDEX IDX_1369EF5CD60322AC TO idx_access_assign_role');
        $this->addSql('ALTER TABLE access_role_assignments RENAME INDEX IDX_1369EF5CA76ED395 TO idx_access_assign_user');
        $this->addSql('ALTER TABLE shop_coupons CHANGE valid_from valid_from DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE valid_until valid_until DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE notification_preferences RENAME INDEX IDX_3CAA95B4E92F8F78 TO idx_notification_pref_recipient');
        $this->addSql('ALTER TABLE shop_product_variants RENAME INDEX IDX_59753EC74584665A TO idx_shop_variant_product');
    }
}
