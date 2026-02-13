<?php

declare(strict_types=1);

namespace App\Tests\Module\Setup\Application;

use App\Module\Setup\Application\EnvFileWriter;
use PHPUnit\Framework\TestCase;

final class EnvFileWriterTest extends TestCase
{
    public function testDoesNotOverwriteExistingKeys(): void
    {
        $writer = new EnvFileWriter();
        $dir = $this->createTempDir();
        file_put_contents($dir . '/.env', "APP_SECRET=existing_secret\n");
        file_put_contents($dir . '/.env.local', "EXISTING_KEY=1\n");

        $written = $writer->setMissingKeys(
            $dir . '/.env.local',
            ['APP_SECRET' => 'new_secret', 'APP_ENCRYPTION_KEYS' => 'v1:abcd'],
            [$dir . '/.env', $dir . '/.env.local'],
        );

        self::assertSame(['APP_ENCRYPTION_KEYS'], $written);
        $content = (string) file_get_contents($dir . '/.env.local');
        self::assertStringNotContainsString('APP_SECRET=new_secret', $content);
        self::assertStringContainsString('APP_ENCRYPTION_KEYS=v1:abcd', $content);
    }

    public function testWritesMissingKeys(): void
    {
        $writer = new EnvFileWriter();
        $dir = $this->createTempDir();

        $written = $writer->setMissingKeys(
            $dir . '/.env.local',
            ['APP_SECRET' => 'new_secret', 'APP_ENCRYPTION_KEYS' => 'v1:abcd'],
            [$dir . '/.env', $dir . '/.env.local'],
        );

        sort($written);
        self::assertSame(['APP_ENCRYPTION_KEYS', 'APP_SECRET'], $written);
        $content = (string) file_get_contents($dir . '/.env.local');
        self::assertStringContainsString('APP_SECRET=new_secret', $content);
        self::assertStringContainsString('APP_ENCRYPTION_KEYS=v1:abcd', $content);
    }


    public function testTreatsEmptyExistingKeyAsPresent(): void
    {
        $writer = new EnvFileWriter();
        $dir = $this->createTempDir();
        file_put_contents($dir . '/.env.local', 'APP_SECRET=
');

        $written = $writer->setMissingKeys(
            $dir . '/.env.local',
            ['APP_SECRET' => 'new_secret'],
            [$dir . '/.env', $dir . '/.env.local'],
        );

        self::assertSame([], $written);
        $content = (string) file_get_contents($dir . '/.env.local');
        self::assertStringNotContainsString('new_secret', $content);
    }

    public function testAtomicWriteReplacesFile(): void
    {
        $writer = new EnvFileWriter();
        $dir = $this->createTempDir();
        $path = $dir . '/.env.local';
        file_put_contents($path, "EXISTING_KEY=1\n");
        $inodeBefore = @fileinode($path);

        $writer->setMissingKeys(
            $path,
            ['APP_SECRET' => 'new_secret'],
            [$dir . '/.env', $path],
        );

        $inodeAfter = @fileinode($path);
        self::assertNotFalse($inodeBefore);
        self::assertNotFalse($inodeAfter);
        self::assertNotSame($inodeBefore, $inodeAfter);
    }

    public function testRejectsIfTargetNotWritable(): void
    {
        $writer = new EnvFileWriter();
        $dir = $this->createTempDir();
        $blocker = $dir . '/blocked';
        file_put_contents($blocker, 'x');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('env_dir_not_writable');

        $writer->setMissingKeys(
            $blocker . '/.env.local',
            ['APP_SECRET' => 'new_secret'],
            [$dir . '/.env'],
        );
    }

    private function createTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/easywi_env_writer_' . bin2hex(random_bytes(4));
        mkdir($dir, 0775, true);

        return $dir;
    }
}
