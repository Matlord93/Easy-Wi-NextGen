<?php
declare(strict_types=1);
namespace App\Tests\Module\Core\Application;
use App\Module\Core\Application\AuditLogCsvExporter;
use PHPUnit\Framework\TestCase;
final class AuditLogCsvExporterTest extends TestCase
{
    public function testExportsHashChainAndPreventsSpreadsheetInjection(): void
    {
        $csv = (new AuditLogCsvExporter())->export([['id' => 1, 'created_at' => '2026-09-20 12:00:00', 'actor_email' => '=cmd()', 'actor_type' => 'admin', 'action' => '+danger', 'payload_preview' => '{"ok":true}', 'hash_prev' => 'abc', 'hash_current' => 'def']]);
        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
        self::assertStringContainsString("'=cmd()", $csv);
        self::assertStringContainsString("'+danger", $csv);
        self::assertStringContainsString('abc;def', $csv);
    }
}
