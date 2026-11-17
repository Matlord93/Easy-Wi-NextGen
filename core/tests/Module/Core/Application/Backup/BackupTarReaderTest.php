<?php

declare(strict_types=1);

namespace App\Tests\Module\Core\Application\Backup;

use App\Module\Core\Application\Backup\{BackupOperationHeartbeat, BackupTarReader, BackupTarWriter, BackupTemporaryDirectory};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BackupTarReaderTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = BackupTemporaryDirectory::create('easywi-backup-snapshot-');
        mkdir($this->directory.'/source', 0700);
        mkdir($this->directory.'/restore', 0700);
    }

    protected function tearDown(): void
    {
        BackupTemporaryDirectory::remove($this->directory, 'easywi-backup-snapshot-');
    }

    public function testRealChunkedPayloadAndManifestRoundTripWithPrivateFilesAndStreamingDigests(): void
    {
        $payload = random_bytes(524305);
        file_put_contents($this->directory.'/source/payload.tar.gz', $payload);
        file_put_contents($this->directory.'/source/resource.json', '{"version":1}');
        (new BackupTarWriter())->write($this->directory.'/source', $this->directory.'/archive.tar');
        $heartbeat = new BackupOperationHeartbeat();
        $pulses = 0;
        $heartbeat->enter(static function () use (&$pulses): void { ++$pulses; });
        $digests = (new BackupTarReader($heartbeat))->extractFiles($this->directory.'/archive.tar', $this->directory.'/restore', ['resource.json' => 64, 'payload.tar.gz' => null]);
        self::assertSame(hash('sha256', $payload), $digests['payload.tar.gz']);
        self::assertSame($payload, file_get_contents($this->directory.'/restore/payload.tar.gz'));
        self::assertSame(0600, fileperms($this->directory.'/restore/payload.tar.gz') & 0777);
        self::assertSame(2, $pulses);
    }

    public function testSmallBase256EncodedEntrySizeIsParsedWithoutPharDataLimits(): void
    {
        $archive = $this->singleEntry();
        $bytes = file_get_contents($archive);
        $header = substr_replace(substr($bytes, 0, 512), "\x80".str_repeat("\0", 10)."\x03", 124, 12);
        file_put_contents($archive, $this->checksum($header).substr($bytes, 512));
        $digests = (new BackupTarReader())->extractFiles($archive, $this->directory.'/restore', ['resource.json' => 3]);
        self::assertSame(hash('sha256', 'abc'), $digests['resource.json']);
        self::assertSame('abc', file_get_contents($this->directory.'/restore/resource.json'));
    }

    /** @return iterable<string, array{string}> */
    public static function malformedArchives(): iterable
    {
        foreach (['checksum', 'traversal', 'unexpected', 'symlink', 'duplicate', 'truncated-data', 'missing-end', 'trailing-data', 'oversized', 'negative-size', 'overflow-size', 'missing-member'] as $fault) {
            yield $fault => [$fault];
        }
    }

    #[DataProvider('malformedArchives')]
    public function testInvalidArchiveIsRejectedAndEveryCreatedStagingMemberIsRemoved(string $fault): void
    {
        $archive = $this->singleEntry();
        $bytes = file_get_contents($archive);
        $header = substr($bytes, 0, 512);
        $allowed = ['resource.json' => 32];
        switch ($fault) {
            case 'checksum': $header[0] = 'x'; break;
            case 'traversal': $header = $this->checksum(substr_replace($header, str_pad('../escape', 100, "\0"), 0, 100)); break;
            case 'unexpected': $header = $this->checksum(substr_replace($header, str_pad('other.json', 100, "\0"), 0, 100)); break;
            case 'symlink': $header = $this->checksum(substr_replace($header, '2', 156, 1)); break;
            case 'oversized': $allowed['resource.json'] = 2; break;
            case 'negative-size': $header = $this->checksum(substr_replace($header, str_repeat("\xff", 12), 124, 12)); break;
            case 'overflow-size': $header = $this->checksum(substr_replace($header, "\x80".str_repeat("\xff", 11), 124, 12)); break;
            case 'missing-member': $allowed['payload.tar.gz'] = null; break;
        }
        $bytes = $header.substr($bytes, 512);
        $bytes = match ($fault) {
            'duplicate' => substr($bytes, 0, 1024).$bytes,
            'truncated-data' => substr($bytes, 0, 513),
            'missing-end' => substr($bytes, 0, -512),
            'trailing-data' => $bytes.'nonzero',
            default => $bytes,
        };
        file_put_contents($archive, $bytes);
        $rejected = false;
        try {
            (new BackupTarReader())->extractFiles($archive, $this->directory.'/restore', $allowed);
        } catch (\RuntimeException|\InvalidArgumentException) {
            $rejected = true;
        }
        self::assertTrue($rejected, $fault);
        self::assertSame(['.', '..'], scandir($this->directory.'/restore'));
    }

    public function testExistingStagingFileIsNeverOverwrittenOrDeleted(): void
    {
        $archive = $this->singleEntry();
        file_put_contents($this->directory.'/restore/resource.json', 'existing');
        try {
            (new BackupTarReader())->extractFiles($archive, $this->directory.'/restore', ['resource.json' => 32]);
        } catch (\RuntimeException) {
        }
        self::assertSame('existing', file_get_contents($this->directory.'/restore/resource.json'));
    }

    private function singleEntry(): string
    {
        file_put_contents($this->directory.'/source/resource.json', 'abc');
        $archive = $this->directory.'/archive.tar';
        (new BackupTarWriter())->write($this->directory.'/source', $archive);
        return $archive;
    }

    private function checksum(string $header): string
    {
        $header = substr_replace($header, str_repeat(' ', 8), 148, 8);
        $sum = 0;
        for ($index = 0; $index < 512; ++$index) {
            $sum += ord($header[$index]);
        }
        return substr_replace($header, sprintf('%06o', $sum)."\0 ", 148, 8);
    }
}
