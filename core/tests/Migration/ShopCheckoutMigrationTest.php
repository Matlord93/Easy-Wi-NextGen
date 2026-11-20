<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use Doctrine\DBAL\DriverManager;
use DoctrineMigrations\Version20261118040000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ShopCheckoutMigrationTest extends TestCase
{
    public function testUpgradePreservesHistoryDoesNotEnableOldSubscriptionsAndIsIdempotent(): void
    {
        require_once __DIR__.'/../../migrations/Version20261118040000.php';
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach (['users', 'invoices', 'shop_coupons'] as $table) {
            $db->executeStatement('CREATE TABLE '.$table.' (id INTEGER PRIMARY KEY)');
        }
        $db->executeStatement('CREATE TABLE shop_categories (id INTEGER PRIMARY KEY, name VARCHAR(120))');
        $db->executeStatement('CREATE TABLE shop_products (id INTEGER PRIMARY KEY, name VARCHAR(160))');
        $db->executeStatement('CREATE TABLE shop_rentals (id INTEGER PRIMARY KEY, expires_at DATETIME)');
        $db->executeStatement('CREATE TABLE shop_orders (id INTEGER PRIMARY KEY, status VARCHAR(255), created_at DATETIME, total_price_cents INTEGER)');
        $db->insert('shop_categories', ['id' => 1, 'name' => 'Keep category']);
        $db->insert('shop_products', ['id' => 1, 'name' => 'Keep product']);
        $db->insert('shop_orders', ['id' => 1, 'status' => 'provisioned', 'created_at' => '2026-10-01 12:00:00', 'total_price_cents' => 1234]);
        $db->insert('shop_rentals', ['id' => 1, 'expires_at' => '2026-12-01 12:00:00']);
        $manager = $db->createSchemaManager();
        $before = $manager->introspectSchema();
        $after = clone $before;
        $migration = new Version20261118040000($db, new NullLogger());
        $migration->up($after);
        $migration->up($after);
        foreach ($db->getDatabasePlatform()->getAlterSchemaSQL($manager->createComparator()->compareSchemas($before, $after)) as $sql) {
            $db->executeStatement($sql);
        }
        self::assertSame('Keep category', $db->fetchOne('SELECT name FROM shop_categories WHERE id=1'));
        self::assertSame(1, (int) $db->fetchOne('SELECT active FROM shop_categories WHERE id=1'));
        self::assertSame(0, (int) $db->fetchOne('SELECT subscription_enabled FROM shop_products WHERE id=1'));
        self::assertSame(0, (int) $db->fetchOne('SELECT auto_renew FROM shop_rentals WHERE id=1'));
        self::assertNull($db->fetchOne('SELECT renewal_configuration FROM shop_rentals WHERE id=1'));
        self::assertSame('provisioned', $db->fetchOne('SELECT status FROM shop_orders WHERE id=1'));
        self::assertSame(1234, (int) $db->fetchOne('SELECT total_price_cents FROM shop_orders WHERE id=1'));
        self::assertNull($db->fetchOne('SELECT invoice_id FROM shop_orders WHERE id=1'));
        self::assertNull($db->fetchOne('SELECT configuration FROM shop_orders WHERE id=1'));
        self::assertTrue($manager->introspectTable('payment_checkouts')->hasIndex('uniq_checkout_provider_reference'));
        $before = $manager->introspectSchema();
        $after = clone $before;
        $migration->up($after);
        self::assertSame([], $db->getDatabasePlatform()->getAlterSchemaSQL($manager->createComparator()->compareSchemas($before, $after)));
        $db->close();
    }
}
