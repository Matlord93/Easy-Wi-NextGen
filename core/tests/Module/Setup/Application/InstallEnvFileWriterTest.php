<?php

declare(strict_types=1);

namespace App\Tests\Module\Setup\Application;

use App\Module\Setup\Application\InstallEnvFileWriter;
use PHPUnit\Framework\TestCase;

final class InstallEnvFileWriterTest extends TestCase
{
    public function testWritesMissingValuesToEnvLocal(): void
    {
        $writer = new InstallEnvFileWriter();
        $dir = sys_get_temp_dir() . '/easywi_env_writer_' . bin2hex(random_bytes(4));
        mkdir($dir, 0775, true);
        $path = $dir . '/.env.local';

        $writer->ensureValues($path, [
            'APP_SECRET' => 'testsecret',
            'APP_ENCRYPTION_KEYS' => 'v1:ZmFrZWtleQ==',
        ]);

        $content = (string) file_get_contents($path);
        self::assertStringContainsString('APP_SECRET=testsecret', $content);
        self::assertStringContainsString('APP_ENCRYPTION_KEYS="v1:ZmFrZWtleQ=="', $content);
    }

    public function testNoWriteWhenEmptyValues(): void
    {
        $writer = new InstallEnvFileWriter();
        $dir = sys_get_temp_dir() . '/easywi_env_writer_' . bin2hex(random_bytes(4));
        mkdir($dir, 0775, true);
        $path = $dir . '/.env.local';

        $writer->ensureValues($path, []);

        self::assertFalse(file_exists($path));
    }
}
