<?php

declare(strict_types=1);

namespace App\Tests\Module\Core\Application\Backup;

use App\Module\Core\Application\Backup\BackupStorageTarget;
use App\Module\Core\Application\Backup\Target\LocalBackupTargetWriter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class LocalBackupPublicationTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir().'/local-backup-publication-'.bin2hex(random_bytes(8));
        mkdir($this->workspace, 0700);
        mkdir($this->workspace.'/target', 0700);
        file_put_contents($this->workspace.'/source', 'new archive data');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->workspace);
    }

    public function testPublishingReplacesSymlinkWithoutOverwritingItsOutsideTarget(): void
    {
        file_put_contents($this->workspace.'/outside', 'outside-original');
        symlink($this->workspace.'/outside', $this->workspace.'/target/archive.tar');

        $path = (new LocalBackupTargetWriter())->write($this->target(), 'archive.tar', $this->workspace.'/source');

        self::assertSame('outside-original', file_get_contents($this->workspace.'/outside'));
        self::assertFalse(is_link($path));
        self::assertSame('new archive data', file_get_contents($path));
        self::assertSame([], glob($this->workspace.'/target/.easywi-backup-*'));
    }

    public function testPublishedArchiveHasPrivatePermissionsAndCompleteContents(): void
    {
        file_put_contents($this->workspace.'/target/archive.tar', 'previous archive');
        chmod($this->workspace.'/target/archive.tar', 0644);

        $path = (new LocalBackupTargetWriter())->write($this->target(), 'archive.tar', $this->workspace.'/source');

        clearstatcache(true, $path);
        self::assertSame(0600, fileperms($path) & 0777);
        self::assertSame('new archive data', file_get_contents($path));
        self::assertSame([], glob($this->workspace.'/target/.easywi-backup-*'));
    }

    public function testFailedPublicationPreservesExistingDestinationAndCleansStagingFile(): void
    {
        mkdir($this->workspace.'/target/archive.tar');
        file_put_contents($this->workspace.'/target/archive.tar/previous', 'original');
        try {
            $this->expectException(\RuntimeException::class);
            (new LocalBackupTargetWriter())->write($this->target(), 'archive.tar', $this->workspace.'/source');
        } finally {
            self::assertSame('original', file_get_contents($this->workspace.'/target/archive.tar/previous'));
            self::assertSame([], glob($this->workspace.'/target/.easywi-backup-*'));
        }
    }

    private function target(): BackupStorageTarget
    {
        return new BackupStorageTarget('local', 'test', ['path' => $this->workspace.'/target']);
    }
}
