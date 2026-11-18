<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use Doctrine\DBAL\DriverManager;
use DoctrineMigrations\Version20261118020000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class CoreTelemetryIndexesMigrationTest extends TestCase
{
    public function testIndexesPreserveExistingRowsAreIdempotentAndCanBeRolledBack(): void
    {
        require_once __DIR__ . '/../../migrations/Version20261118020000.php';
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE jobs (id INTEGER PRIMARY KEY, type VARCHAR(120), status VARCHAR(255), payload TEXT)');
        $db->executeStatement('CREATE TABLE metric_samples (id INTEGER PRIMARY KEY, agent_id VARCHAR(64), recorded_at DATETIME, payload TEXT)');
        $db->insert('jobs', ['id' => 1, 'type' => 'node.disk.stat', 'status' => 'queued', 'payload' => '{"node_id":"a"}']);
        $db->insert('metric_samples', ['id' => 1, 'agent_id' => 'a', 'recorded_at' => '2026-11-18 12:00:00', 'payload' => '{"disk":{"percent":42}}']);
        $manager = $db->createSchemaManager();
        $migration = new Version20261118020000($db, new NullLogger());
        $before = $manager->introspectSchema();
        $after = clone $before;
        $migration->up($after);
        $migration->up($after);
        foreach ($db->getDatabasePlatform()->getAlterSchemaSQL($manager->createComparator()->compareSchemas($before, $after)) as $sql) {
            $db->executeStatement($sql);
        }
        self::assertTrue($manager->introspectTable('jobs')->hasIndex('idx_core_jobs_type_status'));
        self::assertTrue($manager->introspectTable('metric_samples')->hasIndex('idx_metric_samples_agent_recorded_at'));
        self::assertSame('{"node_id":"a"}', $db->fetchOne('SELECT payload FROM jobs WHERE id=1'));
        self::assertSame('{"disk":{"percent":42}}', $db->fetchOne('SELECT payload FROM metric_samples WHERE id=1'));
        $plan = json_encode($db->fetchAllAssociative("EXPLAIN QUERY PLAN SELECT payload FROM jobs WHERE type='node.disk.stat' AND status='queued'"), JSON_THROW_ON_ERROR);
        self::assertStringContainsString('idx_core_jobs_type_status', $plan);
        $plan = json_encode($db->fetchAllAssociative("EXPLAIN QUERY PLAN SELECT id FROM metric_samples WHERE agent_id='a' AND recorded_at>='2026-11-18' ORDER BY recorded_at DESC,id DESC LIMIT 2"), JSON_THROW_ON_ERROR);
        self::assertStringContainsString('idx_metric_samples_agent_recorded_at', $plan);
        $before = $manager->introspectSchema();
        $after = clone $before;
        $migration->down($after);
        foreach ($db->getDatabasePlatform()->getAlterSchemaSQL($manager->createComparator()->compareSchemas($before, $after)) as $sql) {
            $db->executeStatement($sql);
        }
        self::assertFalse($manager->introspectTable('jobs')->hasIndex('idx_core_jobs_type_status'));
        self::assertFalse($manager->introspectTable('metric_samples')->hasIndex('idx_metric_samples_agent_recorded_at'));
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM jobs'));
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM metric_samples'));
        $db->close();
    }
}
