<?php

declare(strict_types=1);

namespace App\Tests\Module\Musicbot\Application\Backup;

use App\Module\Musicbot\Application\Backup\MusicbotBackupAdapter;
use PHPUnit\Framework\TestCase;

final class MusicbotBackupAdapterTest extends TestCase
{
    private string $workspace;
    private MusicbotBackupAdapter $adapter;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir().'/musicbot-backup-adapter-test-'.bin2hex(random_bytes(6));
        mkdir($this->workspace, 0775, true);
        $this->adapter = new MusicbotBackupAdapter();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->workspace)) {
            exec('rm -rf '.escapeshellarg($this->workspace));
        }
    }

    public function testModuleNameIsMusicbot(): void
    {
        self::assertSame('musicbot', $this->adapter->module());
    }

    public function testSnapshotReturnsCorrectArchiveName(): void
    {
        $snapshot = $this->adapter->snapshot('42');

        self::assertSame('42', $snapshot->sourcePath());
        self::assertStringContainsString('42', $snapshot->archiveName());
        self::assertStringContainsString('musicbot', $snapshot->archiveName());
    }

    public function testRestoreFailsForMissingArchive(): void
    {
        $report = $this->adapter->restore('42', '/nonexistent/archive.tar');

        self::assertFalse($report->success());
        self::assertStringContainsString('not found', strtolower($report->message()));
    }

    public function testRestoreBlocksPathTraversalInArchive(): void
    {
        $archivePath = $this->workspace.'/traversal.tar';
        $archive = new \PharData($archivePath);
        $archive->addFromString('../etc/evil.txt', 'malicious');

        $report = $this->adapter->restore('42', $archivePath);

        self::assertFalse($report->success());
        self::assertStringContainsString('validation failed', strtolower($report->message()));
    }

    public function testRestoreSucceedsWithCleanArchive(): void
    {
        $archivePath = $this->workspace.'/clean.tar';
        $archive = new \PharData($archivePath);
        $archive->addFromString('backup.json', '{"schema_version":"1"}');

        $report = $this->adapter->restore('42', $archivePath);

        self::assertTrue($report->success());
    }

    public function testRestoreDryRunIsPreserved(): void
    {
        $archivePath = $this->workspace.'/dryrun.tar';
        $archive = new \PharData($archivePath);
        $archive->addFromString('data.json', '{}');

        $report = $this->adapter->restore('42', $archivePath, true);

        self::assertTrue($report->dryRun());
        self::assertTrue($report->success());
    }
}
