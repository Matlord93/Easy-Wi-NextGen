<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use PHPUnit\Framework\TestCase;

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

    public function testInitialSchemaCreatesCurrentGamePluginsTable(): void
    {
        $migration = self::initialMigrationSql();

        self::assertStringContainsString('CREATE TABLE game_plugins', $migration);
        self::assertStringContainsString('template_id INT NOT NULL', $migration);
        self::assertStringContainsString('download_url VARCHAR(255) NOT NULL', $migration);
        self::assertStringNotContainsString('CREATE TABLE game_template_plugins', $migration);
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
    }

    public function testLegacyMigrationKeepsLegacyTable(): void
    {
        $migration = self::legacyMigrationSql();

        self::assertStringNotContainsString('DROP TABLE game_template_plugins', $migration);
    }
}
