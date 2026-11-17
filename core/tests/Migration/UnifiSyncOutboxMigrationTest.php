<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use DoctrineMigrations\Version20261117190000;
use DoctrineMigrations\Version20261117191000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class UnifiSyncOutboxMigrationTest extends TestCase
{
    public function testMigrationsPreserveAllocationsAndOnlyCascadeDeletedInstances(): void
    {
        require_once __DIR__.'/../../migrations/Version20261117190000.php';
        require_once __DIR__.'/../../migrations/Version20261117191000.php';
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        try {
            $db->executeStatement('PRAGMA foreign_keys = ON');
            $db->executeStatement('CREATE TABLE instances (id INTEGER PRIMARY KEY)');
            $db->executeStatement('CREATE TABLE port_allocations (id INTEGER PRIMARY KEY, instance_id INTEGER NOT NULL, port INTEGER NOT NULL, CONSTRAINT owned_instance FOREIGN KEY (instance_id) REFERENCES instances (id))');
            foreach ([42, 142] as $id) {
                $db->insert('instances', ['id' => $id]);
                $db->insert('port_allocations', ['id' => $id, 'instance_id' => $id, 'port' => 23000 + $id]);
            }
            $manager = $db->createSchemaManager();
            $before = $manager->introspectSchema();
            $after = clone $before;
            (new Version20261117190000($db, new NullLogger()))->up($after);
            $outboxDiff = $manager->createComparator()->compareSchemas($before, $after);
            $mysqlSql = (new MySQLPlatform())->getAlterSchemaSQL($outboxDiff);
            self::assertStringContainsString('CREATE TABLE unifi_sync_requests', implode("\n", $mysqlSql));
            foreach ($db->getDatabasePlatform()->getAlterSchemaSQL($outboxDiff) as $sql) { $db->executeStatement($sql); }
            $ownership = new Version20261117191000($db, new NullLogger());
            $ownership->up($manager->introspectSchema());
            foreach ($ownership->getSql() as $query) { $db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes()); }
            self::assertSame(2, (int) $db->fetchOne('SELECT COUNT(*) FROM port_allocations'));
            $db->insert('unifi_sync_requests', ['instance_id' => 42, 'requested_at' => '2026-11-17 12:00:00', 'available_at' => '2026-11-17 12:00:00']);
            $db->delete('instances', ['id' => 42]);
            self::assertSame([142], array_map('intval', $db->fetchFirstColumn('SELECT instance_id FROM port_allocations')));
            self::assertSame(23142, (int) $db->fetchOne('SELECT port FROM port_allocations'));
            self::assertSame(42, (int) $db->fetchOne('SELECT instance_id FROM unifi_sync_requests'), 'A deletion tombstone must survive its instance.');

            // Generate the same ownership alteration with the production platform, without opening a MySQL connection.
            $mysql = $this->createStub(Connection::class);
            $mysql->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
            $mysqlManager = $this->createStub(AbstractSchemaManager::class);
            $mysqlManager->method('createComparator')->willReturn($manager->createComparator());
            $mysql->method('createSchemaManager')->willReturn($mysqlManager);
            $mysqlMigration = new Version20261117191000($mysql, new NullLogger());
            $mysqlMigration->up($before);
            $sql = implode("\n", array_map(static fn ($query): string => $query->getStatement(), $mysqlMigration->getSql()));
            self::assertStringContainsString('ON DELETE CASCADE', $sql);
            self::assertStringNotContainsString('DROP TABLE', $sql);
        } finally { $db->close(); }
    }
}
