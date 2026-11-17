<?php

declare(strict_types=1);

namespace App\Tests\Module\Musicbot\Application\Backup;

use App\Module\Musicbot\Application\Backup\MusicbotBackupAdapter;
use App\Module\Musicbot\Application\Backup\MusicbotManifestOperationsInterface;
use App\Module\Core\Application\Backup\Adapter\RestoreReport;
use PHPUnit\Framework\TestCase;

final class MusicbotBackupAdapterTest extends TestCase
{
    private string $workspace;
    private MusicbotBackupAdapter $adapter;
    private MusicbotManifestOperationsInterface $operations;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir().'/musicbot-backup-adapter-test-'.bin2hex(random_bytes(6));
        mkdir($this->workspace, 0775, true);
        $this->operations = $this->createStub(MusicbotManifestOperationsInterface::class);
        $this->operations->method('exportManifest')->willReturn('{"schema_version":"1"}');
        $this->operations->method('restoreManifest')->willReturnCallback(static fn (string $id, string $json, bool $dryRun): RestoreReport => new RestoreReport($dryRun, true, 'Configuration restored.'));
        $this->adapter = new MusicbotBackupAdapter($this->operations);
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

        self::assertDirectoryExists($snapshot->sourcePath());
        self::assertSame('{"schema_version":"1"}', file_get_contents($snapshot->sourcePath().'/backup.json'));
        unlink($snapshot->sourcePath().'/backup.json');
        rmdir($snapshot->sourcePath());
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
        self::assertStringContainsString('traversal', strtolower($report->message()));
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
        $archive->addFromString('backup.json', '{}');

        $report = $this->adapter->restore('42', $archivePath, true);

        self::assertTrue($report->dryRun());
        self::assertTrue($report->success());
    }
    public function testUnconfiguredAdapterNeverClaimsRestoreSuccess(): void
    {
        $file = $this->workspace.'/manifest.json';
        file_put_contents($file, '{}');
        $report = (new MusicbotBackupAdapter())->restore('42', $file);
        self::assertFalse($report->success());
        self::assertStringContainsString('not configured', $report->message());
    }

    public function testArchiveWithoutManifestIsRejectedBeforeDelegation(): void
    {
        $path = $this->workspace.'/missing.tar';
        (new \PharData($path))->addFromString('data.json', '{}');
        $operations = $this->createMock(MusicbotManifestOperationsInterface::class);
        $operations->expects(self::never())->method('restoreManifest');
        $report = (new MusicbotBackupAdapter($operations))->restore('42', $path);
        self::assertFalse($report->success());
        self::assertStringContainsString('backup.json', $report->message());
    }

    public function testCleanArchiveActuallyDelegatesTheManifestAndDryRun(): void
    {
        $path = $this->workspace.'/delegate.tar';
        (new \PharData($path))->addFromString('backup.json', '{"test":true}');
        $operations = $this->createMock(MusicbotManifestOperationsInterface::class);
        $operations->expects(self::once())->method('restoreManifest')->with('42', '{"test":true}', true)
            ->willReturn(new RestoreReport(true, true, 'Validated.'));
        self::assertTrue((new MusicbotBackupAdapter($operations))->restore('42', $path, true)->success());
    }

    public function testCompressedArchiveDelegatesOnlyAfterValidationAndCleansItsScratchDirectory(): void
    {
        $path = $this->workspace.'/compressed.tar';
        (new \PharData($path))->addFromString('backup.json', '{}');
        (new \PharData($path))->compress(\Phar::GZ);
        $before = glob(sys_get_temp_dir().'/easywi-musicbot-restore-*');
        $report = $this->adapter->restore('42', $path.'.gz', true);
        self::assertTrue($report->success());
        self::assertSame($before, glob(sys_get_temp_dir().'/easywi-musicbot-restore-*'));
    }
    public function testHardlinksAndSpecialFilesAreRejectedBeforeDelegation(): void
    {
        foreach (['1', '2', '3', '4', '6'] as $flag) {
            $path = $this->workspace.'/special-'.$flag.'.tar';
            (new \PharData($path))->addFromString('backup.json', '{}');
            $bytes = file_get_contents($path);
            $header = substr($bytes, 0, 512);
            $header[156] = $flag;
            $header = substr_replace($header, str_pad('../../outside', 100, "\0"), 157, 100);
            $header = $this->checksum($header);
            file_put_contents($path, $header.substr($bytes, 512));
            $report = $this->adapter->restore('42', $path);
            self::assertFalse($report->success(), $flag);
            self::assertStringContainsString('symlink', $report->message());
        }
    }

    public function testTraversalInUstarPrefixIsRejected(): void
    {
        $path = $this->workspace.'/prefix.tar';
        (new \PharData($path))->addFromString('backup.json', '{}');
        $bytes = file_get_contents($path);
        $header = substr_replace(substr($bytes, 0, 512), str_pad('../outside', 155, "\0"), 345, 155);
        file_put_contents($path, $this->checksum($header).substr($bytes, 512));
        $report = $this->adapter->restore('42', $path);
        self::assertFalse($report->success());
        self::assertStringContainsString('traversal', $report->message());
    }

    public function testCorruptedCompressedArchiveReturnsFailureWithoutLeakingScratchFiles(): void
    {
        $path = $this->workspace.'/broken.tar.gz';
        file_put_contents($path, "\x1f\x8b".str_repeat('invalid', 32));
        $before = glob(sys_get_temp_dir().'/easywi-musicbot-restore-*');
        self::assertFalse($this->adapter->restore('42', $path)->success());
        self::assertSame($before, glob(sys_get_temp_dir().'/easywi-musicbot-restore-*'));
    }

    private function checksum(string $header): string
    {
        $header = substr_replace($header, str_repeat(' ', 8), 148, 8);

        return substr_replace($header, sprintf("%06o\0 ", array_sum(unpack('C*', $header))), 148, 8);
    }
}
