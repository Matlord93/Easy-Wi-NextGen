<?php

declare(strict_types=1);

namespace App\Tests\Module\Core\Application\Backup;

use App\Module\Core\Application\Backup\BackupStorageTarget;
use App\Module\Core\Application\Backup\Target\SmbBackupTargetWriter;
use PHPUnit\Framework\TestCase;

final class SmbBackupProcessTest extends TestCase
{
    public function testExistingDirectoriesDoNotRequireOptionalShadowCopyMetadata(): void
    {
        $directory = sys_get_temp_dir().'/smb-existing-'.bin2hex(random_bytes(8));
        mkdir($directory);
        $binary = $directory.'/smbclient';
        $commandsFile = $directory.'/commands';
        $originalPath = getenv('PATH');
        file_put_contents($binary, '#!/bin/sh'."\n".'printf \'%s\n\' "$6" >> '.escapeshellarg($commandsFile)."\n".'case "$6" in cd*|put*|rename*) exit 0;; *) exit 1;; esac'."\n");
        chmod($binary, 0700);
        putenv('PATH='.$directory.PATH_SEPARATOR.(false === $originalPath ? '' : $originalPath));
        try {
            $target = new BackupStorageTarget('smb', 'SMB', ['host' => 'storage', 'share' => 'backups', 'path' => 'existing'], ['username' => 'backup', 'password' => 'secret']);
            self::assertSame('smb://storage/backups/existing/archive.tar', (new SmbBackupTargetWriter())->write($target, 'archive.tar', '/local/source.tar'));
            $commands = file($commandsFile, FILE_IGNORE_NEW_LINES);
            self::assertIsArray($commands);
            self::assertCount(3, $commands);
            self::assertSame('cd "\\existing"', $commands[0]);
            self::assertStringStartsWith('put ', $commands[1]);
            self::assertStringStartsWith('rename ', $commands[2]);
        } finally {
            putenv(false === $originalPath ? 'PATH' : 'PATH='.$originalPath);
            if (is_file($commandsFile)) {
                unlink($commandsFile);
            }
            unlink($binary);
            rmdir($directory);
        }
    }

    public function testUploadPassesSeparateArgumentsAndRemovesAuthenticationFile(): void
    {
        $this->runUpload(0);
    }

    public function testFailedUploadReportsStatusAndStillRemovesAuthenticationFile(): void
    {
        $this->runUpload(17);
    }

    private function runUpload(int $exitCode): void
    {
        $directory = sys_get_temp_dir().'/smb-process-'.bin2hex(random_bytes(8));
        mkdir($directory);
        $binary = $directory.'/smbclient';
        $argumentsFile = $directory.'/arguments';
        $originalPath = getenv('PATH');
        file_put_contents($binary, '#!/bin/sh'."\n".'printf \'%s\n\' "$@" > '.escapeshellarg($argumentsFile)."\n".'exit '.$exitCode."\n");
        chmod($binary, 0700);
        putenv('PATH='.$directory.PATH_SEPARATOR.(false === $originalPath ? '' : $originalPath));

        try {
            $target = new BackupStorageTarget('smb', 'SMB', ['host' => 'backup.example.test', 'share' => 'Shared Backups'], ['username' => 'backup', 'password' => 'secret']);
            try {
                $destination = (new SmbBackupTargetWriter())->write($target, 'archive name.tar', '/local/source file.tar');
                self::assertSame(0, $exitCode, 'A failed upload must throw.');
                self::assertSame('smb://backup.example.test/Shared Backups/archive name.tar', $destination);
            } catch (\RuntimeException $error) {
                self::assertSame(17, $exitCode);
                self::assertStringContainsString('exit 17', $error->getMessage());
            }

            $arguments = file($argumentsFile, FILE_IGNORE_NEW_LINES);
            self::assertIsArray($arguments);
            self::assertCount(6, $arguments);
            self::assertSame('//backup.example.test/Shared Backups', $arguments[0]);
            self::assertSame('-A', $arguments[1]);
            self::assertFileDoesNotExist($arguments[2]);
            self::assertSame('--option=client signing=required', $arguments[3]);
            self::assertSame('-c', $arguments[4]);
            self::assertMatchesRegularExpression(0 === $exitCode ? '/^rename "\.easywi-upload-[a-f0-9]{32}" "archive name\.tar"$/' : '/^del "\.easywi-upload-[a-f0-9]{32}"$/', $arguments[5]);
        } finally {
            putenv(false === $originalPath ? 'PATH' : 'PATH='.$originalPath);
            if (is_file($argumentsFile)) {
                unlink($argumentsFile);
            }
            unlink($binary);
            rmdir($directory);
        }
    }
}
