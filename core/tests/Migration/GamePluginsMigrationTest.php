<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use Doctrine\DBAL\DriverManager;
use DoctrineMigrations\Version20260531120000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class GamePluginsMigrationTest extends TestCase
{
    private static function initialMigrationSql(): string
    {
        return (string) file_get_contents(__DIR__.'/../../migrations/Version20250101000000.php');
    }

    private static function legacyMigrationSql(): string
    {
        return (string) file_get_contents(__DIR__.'/../../migrations/Version20260531120000.php');
    }

    public function testPluginCatalogIsCreatedByItsOwningMigration(): void
    {
        $migration = self::initialMigrationSql();

        self::assertStringNotContainsString('CREATE TABLE game_plugins', $migration);
        self::assertStringNotContainsString('CREATE TABLE game_template_plugins', $migration);
        self::assertStringContainsString('CREATE TABLE game_plugins', self::legacyMigrationSql());
        self::assertStringContainsString('template_id INT NOT NULL', self::legacyMigrationSql());
        self::assertStringContainsString('download_url VARCHAR(255) NOT NULL', self::legacyMigrationSql());
    }

    public function testLegacyMigrationCopiesDataWithoutDuplicates(): void
    {
        $migration = self::legacyMigrationSql();

        self::assertStringContainsString('FROM game_template_plugins legacy', $migration);
        self::assertStringContainsString('INSERT INTO game_plugins', $migration);
        self::assertStringContainsString('NOT EXISTS', $migration);
        self::assertStringContainsString('current_plugin.template_id = legacy.template_id', $migration);
        self::assertStringContainsString('LOWER(current_plugin.name) = LOWER(legacy.name)', $migration);
        self::assertStringContainsString('current_plugin.version = legacy.version', $migration);
        self::assertStringContainsString('GROUP BY legacy.template_id, LOWER(legacy.name), legacy.version', $migration);
    }

    public function testPluginMigrationsCreateUniqueCatalogIndex(): void
    {
        self::assertStringNotContainsString('ALTER TABLE game_plugins', self::initialMigrationSql());
        self::assertStringContainsString('uq_game_plugins_template_name_version', self::legacyMigrationSql());
    }

    public function testLegacyMigrationKeepsLegacyTable(): void
    {
        $migration = self::legacyMigrationSql();

        self::assertStringNotContainsString('DROP TABLE game_template_plugins', $migration);
    }

    public function testLegacyDataIsRetainedAndCopiedWithoutDuplicatesOnRepeatedUpgrade(): void
    {
        require_once __DIR__.'/../../migrations/Version20260531120000.php';
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        try {
            $db->executeStatement('CREATE TABLE game_template_plugins (template_id INTEGER NOT NULL, name TEXT NOT NULL, version TEXT NOT NULL, checksum TEXT NOT NULL, download_url TEXT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
            foreach (['Admin', 'admin'] as $name) {
                $db->insert('game_template_plugins', ['template_id' => 7, 'name' => $name, 'version' => '1.0', 'checksum' => 'retained-checksum', 'download_url' => 'https://plugins.example.test/admin.zip', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-02 00:00:00']);
            }
            for ($pass = 0; $pass < 2; ++$pass) {
                $migration = new Version20260531120000($db, new NullLogger());
                $migration->up($db->createSchemaManager()->introspectSchema());
                foreach ($migration->getSql() as $query) {
                    $db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
                }
                self::assertSame(1, $db->fetchOne('SELECT COUNT(*) FROM game_plugins'));
            }
            self::assertSame(2, $db->fetchOne('SELECT COUNT(*) FROM game_template_plugins'));
            self::assertSame('retained-checksum', $db->fetchOne('SELECT checksum FROM game_plugins'));
            self::assertSame('extract', $db->fetchOne('SELECT install_mode FROM game_plugins'));
            self::assertTrue($db->createSchemaManager()->introspectTable('game_plugins')->hasIndex('uq_game_plugins_template_name_version'));
        } finally {
            $db->close();
        }
    }
}
