<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use PHPUnit\Framework\TestCase;

final class AgentJobsMigrationCollationTest extends TestCase
{
    private static function migrationSql(): string
    {
        $path = __DIR__.'/../../migrations/Version20260527162740.php';
        self::assertFileIsReadable($path);

        return (string) file_get_contents($path);
    }

    public function testAgentJobsCreateTablePinsLegacyUtf8mb4Collation(): void
    {
        $migration = self::migrationSql();

        self::assertStringContainsString('ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', $migration);
        self::assertStringContainsString('node_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL', $migration);
    }

    public function testAgentJobsForeignKeyReferencesAgentsPrimaryKey(): void
    {
        $migration = self::migrationSql();

        self::assertStringContainsString('INDEX IDX_2789AA3C5C1662B (node_id)', $migration);
        self::assertStringContainsString('FOREIGN KEY (node_id) REFERENCES agents (id)', $migration);
    }

    public function testAgentJobsMigrationDoesNotUseMariaDbUca1400DefaultCollation(): void
    {
        $migration = self::migrationSql();

        self::assertStringNotContainsString('uca1400', strtolower($migration));
    }
}
