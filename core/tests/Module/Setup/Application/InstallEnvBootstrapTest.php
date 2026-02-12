<?php

declare(strict_types=1);

namespace App\Tests\Module\Setup\Application;

use App\Module\Setup\Application\InstallEnvBootstrap;
use PHPUnit\Framework\TestCase;

final class InstallEnvBootstrapTest extends TestCase
{
    public function testEnsureWritesAllMissingKeys(): void
    {
        $dir = $this->createTempDir();
        file_put_contents($dir . '/.env', "APP_ENV=prod\n");

        $result = (new InstallEnvBootstrap())->ensure($dir);

        self::assertTrue($result['ok']);
        $content = (string) file_get_contents($dir . '/.env.local');
        self::assertStringContainsString('APP_ENCRYPTION_KEYS=', $content);
        self::assertStringContainsString('APP_ENCRYPTION_ACTIVE_KEY_ID=', $content);
    }

    public function testEmptyLookingKeyIsTreatedAsPresent(): void
    {
        $dir = $this->createTempDir();
        file_put_contents($dir . '/.env.local', "APP_SECRET=\n");

        $result = (new InstallEnvBootstrap())->ensure($dir);

        self::assertTrue($result['ok']);
        $content = (string) file_get_contents($dir . '/.env.local');
        self::assertSame(1, substr_count($content, 'APP_SECRET='));
    }

    private function createTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/easywi_install_env_' . bin2hex(random_bytes(4));
        mkdir($dir, 0775, true);

        return $dir;
    }
}
