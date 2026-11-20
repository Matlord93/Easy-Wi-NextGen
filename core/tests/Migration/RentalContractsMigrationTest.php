<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use Doctrine\DBAL\DriverManager;
use DoctrineMigrations\Version20261118050000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class RentalContractsMigrationTest extends TestCase
{
    public function testMigrationPreservesExistingProductTargetsAndAllowsIndependentProductKinds(): void
    {
        require_once __DIR__.'/../../migrations/Version20261118050000.php';
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE users (id INTEGER PRIMARY KEY)');
        $db->executeStatement('CREATE TABLE shop_orders (id INTEGER PRIMARY KEY)');
        $db->executeStatement('CREATE TABLE shop_products (id INTEGER PRIMARY KEY, template_id INTEGER NOT NULL, node_id VARCHAR(64) NOT NULL)');
        $db->insert('shop_products', ['id' => 1, 'template_id' => 7, 'node_id' => 'existing-node']);
        $manager = $db->createSchemaManager();
        $before = $manager->introspectSchema();
        $after = clone $before;
        $migration = new Version20261118050000($db, new NullLogger());
        $migration->up($after);
        $migration->up($after);
        foreach ($db->getDatabasePlatform()->getAlterSchemaSQL($manager->createComparator()->compareSchemas($before, $after)) as $sql) {
            $db->executeStatement($sql);
        }
        self::assertSame('gameserver', $db->fetchOne('SELECT kind FROM shop_products WHERE id=1'));
        self::assertSame(7, (int) $db->fetchOne('SELECT template_id FROM shop_products WHERE id=1'));
        self::assertSame('existing-node', $db->fetchOne('SELECT node_id FROM shop_products WHERE id=1'));
        $db->insert('shop_products', ['id' => 2, 'template_id' => null, 'node_id' => null, 'kind' => 'domain']);
        self::assertSame('domain', $db->fetchOne('SELECT kind FROM shop_products WHERE id=2'));
        foreach (['rental_accounts', 'rental_credits', 'shop_contracts', 'shop_domain_terms'] as $table) {
            self::assertTrue($manager->tablesExist([$table]));
        }
        $before = $manager->introspectSchema();
        $after = clone $before;
        $migration->up($after);
        self::assertSame([], $db->getDatabasePlatform()->getAlterSchemaSQL($manager->createComparator()->compareSchemas($before, $after)));
        $db->close();
    }
}
