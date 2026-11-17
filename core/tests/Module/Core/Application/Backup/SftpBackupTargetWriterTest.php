<?php

declare(strict_types=1);

namespace App\Tests\Module\Core\Application\Backup;

use App\Infrastructure\Security\PrivateKeyFileReader;
use App\Module\Core\Application\Backup\BackupRun;
use App\Module\Core\Application\Backup\BackupStorageTarget;
use App\Module\Core\Application\Backup\Target\SftpBackupTargetWriter;
use App\Module\Core\Application\SftpConnectionFactory;
use App\Module\Core\Application\SshHostKeyVerifier;
use phpseclib3\Net\SFTP;
use PHPUnit\Framework\TestCase;

final class SftpBackupTargetWriterTest extends TestCase
{
    public function testSuccessfulCallbackDownloadAndQuarantineDeletionPreservePublicSftpContract(): void
    {
        $files = ['/backups/archive.tar' => 'archive'];
        $writer = $this->writer($files);
        $beats = 0;
        $heartbeat = static function () use (&$beats): void { ++$beats; };
        $proof = $writer->inspect($this->target(), 'sftp://storage:22/backups/archive.tar', $heartbeat);
        self::assertSame(7, $proof->size);
        self::assertSame(hash('sha256', 'archive'), $proof->sha256);
        $writer->delete($this->target(), $this->archiveRun(), $proof, $heartbeat);
        self::assertSame([], $files);
        self::assertGreaterThan(2, $beats);
    }

    public function testChangedIsolatedArchiveIsRestoredWithoutDeletion(): void
    {
        $files = ['/backups/archive.tar' => 'archive'];
        $writer = $this->writer($files, true);
        $heartbeat = static function (): void {};
        $proof = $writer->inspect($this->target(), 'sftp://storage:22/backups/archive.tar', $heartbeat);
        try {
            $writer->delete($this->target(), $this->archiveRun(), $proof, $heartbeat);
            self::fail('A changed quarantine must not be deleted.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('original archive restored', $error->getMessage());
        }
        self::assertSame(['/backups/archive.tar' => 'changed'], $files);
    }

    public function testUploadStagesPrivatelyBeforePublishingAndNeverOverwritesExistingArchive(): void
    {
        $files = [];
        $writer = $this->writer($files);
        $source = tempnam(sys_get_temp_dir(), 'sftp-source-');
        self::assertIsString($source);
        file_put_contents($source, 'archive');
        try {
            self::assertSame('sftp://storage:22/backups/archive.tar', $writer->write($this->target(), 'archive.tar', $source));
            self::assertSame(['/backups/archive.tar' => 'archive'], $files);
            file_put_contents($source, 'replacement');
            try {
                $writer->write($this->target(), 'archive.tar', $source);
                self::fail('A published archive must not be silently overwritten.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('publication failed', $error->getMessage());
            }
            self::assertSame(['/backups/archive.tar' => 'archive'], $files);
        } finally {
            unlink($source);
        }
    }

    public function testLostConnectionDuringRestorationReportsTheRetainedQuarantine(): void
    {
        $files = ['/backups/archive.tar' => 'archive'];
        $writer = $this->writer($files, true, true);
        $heartbeat = static function (): void {};
        $proof = $writer->inspect($this->target(), 'sftp://storage:22/backups/archive.tar', $heartbeat);
        try {
            $writer->delete($this->target(), $this->archiveRun(), $proof, $heartbeat);
            self::fail('A changed archive must not be deleted.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('isolated archive retained at /backups/.easywi-retention-', $error->getMessage());
        }
        self::assertArrayNotHasKey('/backups/archive.tar', $files);
        self::assertCount(1, $files);
        self::assertSame(['changed'], array_values($files));
    }

    /** @param array<string,string> $files */
    private function writer(array &$files, bool $changeDuringRename = false, bool $failRestoration = false): SftpBackupTargetWriter
    {
        $connection = $this->createStub(SFTP::class);
        $connection->method('getServerPublicHostKey')->willReturn('ssh-ed25519 '.base64_encode('server-key'));
        $connection->method('login')->willReturn(true);
        $connection->method('is_dir')->willReturn(true);
        $connection->method('realpath')->willReturnCallback(static fn (string $path): string => $path);
        $connection->method('lstat')->willReturnCallback(static function (string $path) use (&$files): array|false {
            if ('/backups' === $path) {
                return ['type' => 2];
            }
            if (!isset($files[$path])) {
                return false;
            }

            return ['type' => 1, 'size' => strlen($files[$path]), 'mtime' => 123, 'mode' => 0100600, 'uid' => 1, 'gid' => 1];
        });
        $connection->method('get')->willReturnCallback(static function (string $path, callable $output) use (&$files): string|false {
            if (!isset($files[$path])) {
                return false;
            }
            $output($files[$path]);

            return ''; // Actual phpseclib callback-mode success result.
        });
        $connection->method('rename')->willReturnCallback(static function (string $from, string $to) use (&$files, $changeDuringRename, $failRestoration): bool {
            if ($failRestoration && str_contains($from, '/.easywi-retention-')) {
                throw new \RuntimeException('Connection lost.');
            }
            if (!isset($files[$from]) || isset($files[$to])) {
                return false;
            }
            $files[$to] = $changeDuringRename && str_contains($to, '/.easywi-retention-') ? 'changed' : $files[$from];
            unset($files[$from]);

            return true;
        });
        $connection->method('delete')->willReturnCallback(static function (string $path) use (&$files): bool {
            if (!isset($files[$path])) {
                return false;
            }
            unset($files[$path]);

            return true;
        });
        $connection->method('put')->willReturnCallback(static function (string $path, string $source) use (&$files): bool {
            $data = file_get_contents($source);
            self::assertIsString($data);
            self::assertStringContainsString('/.easywi-upload-', $path);
            $files[$path] = $data;

            return true;
        });
        $connection->method('chmod')->willReturn(true);
        $factory = $this->createStub(SftpConnectionFactory::class);
        $factory->method('create')->willReturn($connection);

        return new SftpBackupTargetWriter($factory, new SshHostKeyVerifier(), new PrivateKeyFileReader());
    }

    private function target(): BackupStorageTarget
    {
        return new BackupStorageTarget('sftp', 'SFTP', ['host' => 'storage', 'path' => '/backups', 'host_key_fingerprint' => 'SHA256:'.rtrim(base64_encode(hash('sha256', 'server-key', true)), '=')], ['username' => 'backup', 'password' => 'secret']);
    }

    private function archiveRun(): BackupRun
    {
        return new BackupRun('run', 'plan', 'succeeded', 'sftp://storage:22/backups/archive.tar', 7, hash('sha256', 'archive'), new \DateTimeImmutable());
    }
}
