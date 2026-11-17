<?php

declare(strict_types=1);

namespace App\Tests\Module\Core\Application\Backup;

use App\Module\Core\Application\Backup\Adapter\{BackupModuleAdapterInterface, BackupSnapshot, RestoreReport, WebBackupAdapter};
use App\Module\Core\Application\Backup\{BackupAdapterRegistry, BackupAgent, BackupArchiveCodec, BackupPlan, BackupStorageTarget, BackupTargetValidator, BackupTargetWriterRegistry, BackupTemporaryDirectory, RetentionPolicy};
use App\Module\Core\Application\Backup\Target\{BackupTargetWriterInterface, LocalBackupTargetWriter};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BackupArchivePipelineTest extends TestCase
{
    private string $workspace;
    protected function setUp(): void { $this->workspace = BackupTemporaryDirectory::create(); }
    protected function tearDown(): void { BackupTemporaryDirectory::remove($this->workspace, 'easywi-backup-work-'); }

    public static function formats(): iterable
    {
        yield 'plain' => [[]];
        yield 'gzip' => [['compression' => 'gzip']];
        yield 'encrypted' => [['encrypt' => true, 'encryption_key' => 'secret']];
        yield 'encrypted gzip' => [['compression' => 'gzip', 'encrypt' => true, 'encryption_key' => 'secret']];
    }

    #[DataProvider('formats')]
    public function testRoundTripAndDryRun(array $options): void
    {
        $source = $this->workspace.'/source'; mkdir($source, 0700);
        $original = str_repeat('large backup content', 100000);
        file_put_contents($source.'/data.txt', $original);
        $plan = $this->plan($source, $options); $agent = $this->agent();
        $run = $agent->run($plan, 'stable-id');
        self::assertSame('stable-id', $run->runId());
        self::assertSame(hash_file('sha256', $run->archivePath()), $run->checksumSha256());
        file_put_contents($source.'/data.txt', 'changed');
        self::assertTrue($agent->restore($plan, $run->archivePath(), true)->success());
        self::assertSame('changed', file_get_contents($source.'/data.txt'));
        self::assertTrue($agent->restore($plan, $run->archivePath())->success());
        self::assertSame($original, file_get_contents($source.'/data.txt'));
    }

    public static function boundarySizes(): iterable
    {
        foreach ([0, 1, 15, 16, 17, 1048576, 1048593, 2097152] as $size) { yield (string) $size => [$size]; }
    }

    #[DataProvider('boundarySizes')]
    public function testLegacyEncryptionCompatibilityAtBoundaries(int $size): void
    {
        $plain = $size === 0 ? '' : random_bytes($size);
        file_put_contents($this->workspace.'/plain', $plain);
        $codec = new BackupArchiveCodec();
        $codec->encrypt($this->workspace.'/plain', $this->workspace.'/encrypted', 'secret');
        $bytes = file_get_contents($this->workspace.'/encrypted');
        self::assertIsString($bytes);
        self::assertSame($plain, openssl_decrypt(substr($bytes, 16), 'AES-256-CBC', hash('sha256', 'secret', true), OPENSSL_RAW_DATA, substr($bytes, 0, 16)));
        $codec->decrypt($this->workspace.'/encrypted', $this->workspace.'/decoded', 'secret');
        self::assertSame($plain, file_get_contents($this->workspace.'/decoded'));
        $iv = str_repeat('i', 16);
        file_put_contents($this->workspace.'/legacy', $iv.openssl_encrypt($plain, 'AES-256-CBC', hash('sha256', 'secret', true), OPENSSL_RAW_DATA, $iv));
        $codec->decrypt($this->workspace.'/legacy', $this->workspace.'/legacy-decoded', 'secret');
        self::assertSame($plain, file_get_contents($this->workspace.'/legacy-decoded'));
    }

    public function testWrongKeyAndTruncationNeverModifyTarget(): void
    {
        $source = $this->workspace.'/source'; mkdir($source, 0700); file_put_contents($source.'/data.txt', 'original');
        $agent = $this->agent(); $plan = $this->plan($source, ['encrypt' => true, 'encryption_key' => 'correct']);
        $run = $agent->run($plan); file_put_contents($source.'/data.txt', 'keep');
        self::assertFalse($agent->restore($this->plan($source, ['encrypt' => true, 'encryption_key' => 'wrong']), $run->archivePath())->success());
        file_put_contents($this->workspace.'/broken.enc', substr(file_get_contents($run->archivePath()), 0, -1));
        self::assertFalse($agent->restore($plan, $this->workspace.'/broken.enc')->success());
        self::assertSame('keep', file_get_contents($source.'/data.txt'));
    }

    public function testTruncatedGzipIsRejected(): void
    {
        file_put_contents($this->workspace.'/broken.gz', substr(gzencode('payload'), 0, -4));
        $this->expectException(\RuntimeException::class);
        (new BackupArchiveCodec())->gunzip($this->workspace.'/broken.gz', $this->workspace.'/decoded');
    }

    public function testWriterFailureCleansOwnedSourcesAndPrivateStagingWithUnsafeIds(): void
    {
        $snapshotDir = BackupTemporaryDirectory::create('easywi-backup-snapshot-'); file_put_contents($snapshotDir.'/backup.json', '{}');
        $adapter = new class($snapshotDir) implements BackupModuleAdapterInterface {
            public function __construct(private string $directory) {}
            public function module(): string { return 'test'; }
            public function snapshot(string $resourceId): BackupSnapshot { return BackupSnapshot::temporaryDirectory($this->directory, 'backup.tar'); }
            public function restore(string $resourceId, string $archivePath, bool $dryRun = false): RestoreReport { throw new \LogicException('not used'); }
        };
        $writer = new class implements BackupTargetWriterInterface {
            public ?string $stagedFile = null;
            public function supports(BackupStorageTarget $target): bool { return true; }
            public function write(BackupStorageTarget $target, string $archiveName, string $sourceFile): string {
                $this->stagedFile = $sourceFile;
                TestCase::assertSame(0700, fileperms(dirname($sourceFile)) & 0777);
                throw new \RuntimeException('writer unavailable');
            }
        };
        $plan = new BackupPlan('../../unsafe-plan', 'test', 'test-resource', new BackupStorageTarget('local', 'test', ['path' => $this->workspace]), new RetentionPolicy(1, 1));
        $agent = new BackupAgent(new BackupTargetValidator(), new BackupTargetWriterRegistry([$writer]), new BackupAdapterRegistry([$adapter]));
        try { $agent->run($plan, '../../unsafe-run'); self::fail('Writer failure must propagate.'); }
        catch (\RuntimeException $error) { self::assertSame('writer unavailable', $error->getMessage()); }
        self::assertNotNull($writer->stagedFile);
        self::assertFileDoesNotExist($writer->stagedFile);
        self::assertDirectoryDoesNotExist(dirname($writer->stagedFile));
        self::assertDirectoryDoesNotExist($snapshotDir);
    }

    public function testOrdinarySnapshotNeverDeletesItsSource(): void
    {
        (new BackupSnapshot($this->workspace, 'backup.tar'))->cleanup();
        self::assertDirectoryExists($this->workspace);
        $this->expectException(\InvalidArgumentException::class);
        BackupSnapshot::temporaryDirectory($this->workspace, 'backup.tar');
    }

    public function testRestoreFailureCleansDecodedFilesAndRetainsOriginalArchive(): void
    {
        $source = $this->workspace.'/source';
        mkdir($source, 0700);
        file_put_contents($source.'/data.txt', 'original');
        $plan = $this->plan($source, ['compression' => 'gzip', 'encrypt' => true, 'encryption_key' => 'secret']);
        $run = $this->agent()->run($plan);
        $adapter = new class implements BackupModuleAdapterInterface {
            public ?string $decodedPath = null;
            public function module(): string { return 'web'; }
            public function snapshot(string $resourceId): BackupSnapshot { throw new \LogicException('not used'); }
            public function restore(string $resourceId, string $archivePath, bool $dryRun = false): RestoreReport {
                TestCase::assertFileExists($archivePath);
                $this->decodedPath = $archivePath;
                throw new \RuntimeException('adapter unavailable');
            }
        };
        $agent = new BackupAgent(new BackupTargetValidator(), new BackupTargetWriterRegistry([]), new BackupAdapterRegistry([$adapter]));
        self::assertFalse($agent->restore($plan, $run->archivePath())->success());
        self::assertNotNull($adapter->decodedPath);
        self::assertFileDoesNotExist($adapter->decodedPath);
        self::assertDirectoryDoesNotExist(dirname($adapter->decodedPath));
        self::assertFileExists($run->archivePath());
        self::assertSame('original', file_get_contents($source.'/data.txt'));
    }

    public function testOwnedSnapshotCleanupDoesNotFollowLinks(): void
    {
        $owned = BackupTemporaryDirectory::create('easywi-backup-snapshot-');
        file_put_contents($this->workspace.'/keep.txt', 'keep');
        self::assertTrue(symlink($this->workspace, $owned.'/external'));
        BackupSnapshot::temporaryDirectory($owned, 'backup.tar')->cleanup();
        self::assertDirectoryDoesNotExist($owned);
        self::assertSame('keep', file_get_contents($this->workspace.'/keep.txt'));
    }

    public function testRepeatedIdempotencyNameCannotReuseStaleArchiveEntries(): void
    {
        $source = $this->workspace.'/source';
        mkdir($source, 0700);
        file_put_contents($source.'/old.txt', 'old');
        $plan = $this->plan($source, []);
        $agent = $this->agent();
        $first = $agent->run($plan, 'same-id');
        unlink($source.'/old.txt');
        file_put_contents($source.'/new.txt', 'new');
        $second = $agent->run($plan, 'same-id');
        self::assertSame($first->archivePath(), $second->archivePath());
        $archive = new \PharData($second->archivePath());
        self::assertFalse(isset($archive['old.txt']));
        self::assertTrue(isset($archive['new.txt']));
    }

    public function testStagingPrefixCannotEscapeTemporaryParent(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BackupTemporaryDirectory::create('../outside-');
    }

    public function testAmbiguousPlanAndRunNamesCannotOverwriteAnotherPlansArchive(): void
    {
        $source = $this->workspace.'/source';
        mkdir($source, 0700);
        $target = new BackupStorageTarget('local', 'test', ['path' => $this->workspace.'/destination']);
        $firstPlan = new BackupPlan('a-b', 'web', $source, $target, new RetentionPolicy(1, 1));
        $secondPlan = new BackupPlan('a', 'web', $source, $target, new RetentionPolicy(1, 1));
        file_put_contents($source.'/data.txt', 'first plan contents');
        $first = $this->agent()->run($firstPlan, 'c');
        file_put_contents($source.'/data.txt', 'second plan contents');
        $second = $this->agent()->run($secondPlan, 'b-c');

        self::assertNotSame($first->archivePath(), $second->archivePath());
        self::assertSame('first plan contents', (new \PharData($first->archivePath()))['data.txt']->getContent());
        self::assertSame('second plan contents', (new \PharData($second->archivePath()))['data.txt']->getContent());
    }

    public function testModuleArchiveNameIsHonoredWithoutExceedingFilesystemNameLimit(): void
    {
        $source = $this->workspace.'/source';
        mkdir($source, 0700);
        file_put_contents($source.'/data.txt', 'module data');
        $adapter = $this->createStub(BackupModuleAdapterInterface::class);
        $adapter->method('module')->willReturn('web');
        $adapter->method('snapshot')->willReturn(new BackupSnapshot($source, 'custom-export.sql'));
        $agent = new BackupAgent(new BackupTargetValidator(), new BackupTargetWriterRegistry([new LocalBackupTargetWriter()]), new BackupAdapterRegistry([$adapter]));
        $plan = new BackupPlan(str_repeat('p', 100), 'web', $source, new BackupStorageTarget('local', 'test', ['path' => $this->workspace.'/destination']), new RetentionPolicy(1, 1));

        $run = $agent->run($plan, str_repeat('r', 100));

        self::assertStringStartsWith(str_repeat('p', 100).'-custom-export-', basename($run->archivePath()));
        self::assertLessThanOrEqual(255, strlen(basename($run->archivePath())));
        self::assertStringEndsWith('.tar', $run->archivePath());
        self::assertSame('module data', (new \PharData($run->archivePath()))['data.txt']->getContent());
    }

    private function plan(string $source, array $options): BackupPlan
    {
        return new BackupPlan('plan', 'web', $source, new BackupStorageTarget('local', 'test', ['path' => $this->workspace.'/destination']), new RetentionPolicy(1, 1), options: $options);
    }
    private function agent(): BackupAgent
    {
        return new BackupAgent(new BackupTargetValidator(), new BackupTargetWriterRegistry([new LocalBackupTargetWriter()]), new BackupAdapterRegistry([new WebBackupAdapter()]));
    }
}
