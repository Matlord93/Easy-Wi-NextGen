<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use Doctrine\DBAL\DriverManager;
use DoctrineMigrations\Version20261118060000;
use DoctrineMigrations\Version20261118061000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class RentalExpansionMigrationTest extends TestCase
{
    public function testUpgradePreservesBalancesAndCreatesAnIdempotentOpeningLedgerIncludingUnsettledUsage(): void
    {
        require_once __DIR__.'/../../migrations/Version20261118060000.php';
        require_once __DIR__.'/../../migrations/Version20261118061000.php';
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach ([
            'CREATE TABLE users (id INTEGER PRIMARY KEY)',
            'CREATE TABLE invoices (id INTEGER PRIMARY KEY)',
            'CREATE TABLE jobs (id VARCHAR(32) PRIMARY KEY)',
            'CREATE TABLE rental_accounts (id INTEGER PRIMARY KEY, balance_units BIGINT NOT NULL)',
            'CREATE TABLE shop_contracts (id INTEGER PRIMARY KEY, account_id INTEGER NOT NULL, status VARCHAR(24) NOT NULL DEFAULT \'active\', billing_mode VARCHAR(12) NOT NULL, pending_units BIGINT NOT NULL)',
            'CREATE TABLE payment_checkouts (id VARCHAR(48) PRIMARY KEY, invoice_id INTEGER NOT NULL)',
            'CREATE TABLE shop_domain_terms (id INTEGER PRIMARY KEY, actor_id INTEGER NOT NULL)',
            'CREATE TABLE backups (id INTEGER PRIMARY KEY)',
        ] as $sql) {
            $db->executeStatement($sql);
        }
        $db->insert('rental_accounts', ['id' => 1, 'balance_units' => 100000]);
        $db->insert('rental_accounts', ['id' => 2, 'balance_units' => 0]);
        $db->insert('shop_contracts', ['id' => 1, 'account_id' => 1, 'billing_mode' => 'wallet', 'pending_units' => 2400]);
        $db->insert('shop_contracts', ['id' => 2, 'account_id' => 1, 'billing_mode' => 'postpaid', 'pending_units' => 99999]);
        $manager = $db->createSchemaManager();
        $before = $manager->introspectSchema();
        $after = clone $before;
        $migration = new Version20261118060000($db, new NullLogger());
        $migration->up($after);
        foreach ($db->getDatabasePlatform()->getAlterSchemaSQL($manager->createComparator()->compareSchemas($before, $after)) as $sql) {
            $db->executeStatement($sql);
        }
        self::assertSame(100000, (int) $db->fetchOne('SELECT balance_units FROM rental_accounts WHERE id=1'));
        self::assertSame(0, (int) $db->fetchOne('SELECT postpaid_reserved_units FROM rental_accounts WHERE id=1'));
        self::assertFalse($manager->introspectTable('payment_checkouts')->getColumn('invoice_id')->getNotnull());
        self::assertFalse($manager->introspectTable('shop_domain_terms')->getColumn('actor_id')->getNotnull());
        foreach (['wallet_topups', 'wallet_entries', 'rental_capacity_pools', 'rental_capacity_leases', 'rental_notices', 'registrar_contacts', 'registrar_operations'] as $table) {
            self::assertTrue($manager->tablesExist([$table]));
        }
        $before = $manager->introspectSchema();
        $after = clone $before;
        $migration->up($after);
        self::assertSame([], $db->getDatabasePlatform()->getAlterSchemaSQL($manager->createComparator()->compareSchemas($before, $after)));
        $opening = new Version20261118061000($db, new NullLogger());
        $opening->up($after);
        self::assertCount(1, $opening->getSql());
        foreach ([1, 2] as $pass) {
            foreach ($opening->getSql() as $query) {
                $db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
        }
        self::assertSame(2, (int) $db->fetchOne('SELECT COUNT(*) FROM wallet_entries'));
        self::assertSame(102400, (int) $db->fetchOne('SELECT units FROM wallet_entries WHERE account_id=1'));
        self::assertSame(0, (int) $db->fetchOne('SELECT units FROM wallet_entries WHERE account_id=2'));
        self::assertSame(100000, (int) $db->fetchOne("SELECT SUM(units) - (SELECT SUM(pending_units) FROM shop_contracts WHERE account_id=1 AND billing_mode='wallet') FROM wallet_entries WHERE account_id=1"));
        $db->close();
    }
}
