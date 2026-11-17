<?php

declare(strict_types=1);

namespace App\Tests\Module\Core\Application\Backup;

use App\Module\Core\Application\Backup\BackupOperationHeartbeat;
use App\Module\Core\Application\Backup\BackupTarWriter;
use App\Module\Core\Application\Backup\BackupTemporaryDirectory;
use PHPUnit\Framework\TestCase;

final class BackupTarWriterTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = BackupTemporaryDirectory::create('easywi-backup-snapshot-');
        mkdir($this->directory.'/source', 0700);
    }

    protected function tearDown(): void
    {
        BackupTemporaryDirectory::remove($this->directory, 'easywi-backup-snapshot-');
    }

    public function testPharDataReadsBinaryNestedHiddenEmptyAndLongPathMembers(): void
    {
        $longPath = str_repeat('long-directory-name-', 8).'/'.str_repeat('long-filename-', 12).'.bin';
        mkdir($this->directory.'/source/'.dirname($longPath), 0700, true);
        $files = ['.hidden' => 'hidden bytes', 'empty' => '', $longPath => random_bytes(4097), 'plain.bin' => "\0\xff\x01\0binary\n"];
        foreach ($files as $name => $bytes) {
            file_put_contents($this->directory.'/source/'.$name, $bytes);
        }
        $archive = $this->directory.'/backup.tar';
        (new BackupTarWriter())->write($this->directory.'/source', $archive);
        self::assertSame(0600, fileperms($archive) & 0777);
        $tar = new \PharData($archive);
        self::assertCount(count($files), $tar);
        foreach ($files as $name => $bytes) {
            self::assertSame($bytes, $tar[$name]->getContent(), $name);
        }
    }

    public function testSingleLargeFileIsStreamedAndLeaseFailureRemovesPartialArchive(): void
    {
        $source = fopen($this->directory.'/source/large.bin', 'wb');
        self::assertIsResource($source);
        $block = str_repeat('binary-block', 87382);
        for ($index = 0; $index < 12; ++$index) {
            fwrite($source, $block);
        }
        fclose($source);
        $heartbeat = new BackupOperationHeartbeat();
        $pulses = 0;
        $heartbeat->enter(function () use (&$pulses): void {
            ++$pulses;
            if ($pulses === 2) {
                throw new \RuntimeException('Lease lost before archive publication.');
            }
        });
        $error = null;
        try {
            (new BackupTarWriter())->write($this->directory.'/source', $this->directory.'/failed.tar', $heartbeat);
        } catch (\RuntimeException $failure) {
            $error = $failure;
        }
        self::assertNotNull($error);
        self::assertSame(2, $pulses);
        self::assertFileDoesNotExist($this->directory.'/failed.tar');
        (new BackupTarWriter())->write($this->directory.'/source', $this->directory.'/complete.tar');
        $tar = new \PharData($this->directory.'/complete.tar');
        self::assertSame(hash_file('sha256', $this->directory.'/source/large.bin'), hash('sha256', $tar['large.bin']->getContent()));
    }

    public function testSymlinksAreRejectedAndPartialArchiveIsRemoved(): void
    {
        file_put_contents($this->directory.'/source/first', 'legitimate');
        symlink('/etc/passwd', $this->directory.'/source/link');
        $error = null;
        try {
            (new BackupTarWriter())->write($this->directory.'/source', $this->directory.'/unsafe.tar');
        } catch (\InvalidArgumentException $failure) {
            $error = $failure;
        }
        self::assertNotNull($error);
        self::assertFileDoesNotExist($this->directory.'/unsafe.tar');
    }

    public function testExistingDestinationIsPreserved(): void
    {
        file_put_contents($this->directory.'/existing.tar', 'existing archive');
        $error = null;
        try {
            (new BackupTarWriter())->write($this->directory.'/source', $this->directory.'/existing.tar');
        } catch (\RuntimeException $failure) {
            $error = $failure;
        }
        self::assertNotNull($error);
        self::assertSame('existing archive', file_get_contents($this->directory.'/existing.tar'));
    }

    public function testNamedFilesFromDifferentDirectoriesBecomeExactRestoreEnvelope(): void
    {
        mkdir($this->directory.'/elsewhere', 0700);
        file_put_contents($this->directory.'/source/config.json', '{"module":"game"}');
        file_put_contents($this->directory.'/elsewhere/opaque.gz', "binary\0payload");
        (new BackupTarWriter())->writeFiles(['descriptor.json' => $this->directory.'/source/config.json', 'archive.tar.gz' => $this->directory.'/elsewhere/opaque.gz'], $this->directory.'/envelope.tar');
        $tar = new \PharData($this->directory.'/envelope.tar');
        self::assertCount(2, $tar);
        self::assertSame('{"module":"game"}', $tar['descriptor.json']->getContent());
        self::assertSame("binary\0payload", $tar['archive.tar.gz']->getContent());
    }

    public function testOutputInsideSourceCannotIncludeItself(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new BackupTarWriter())->write($this->directory.'/source', $this->directory.'/source/self.tar');
    }
}
