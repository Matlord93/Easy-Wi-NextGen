<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Infrastructure\Security\EncryptionKeyLoader;
use App\Infrastructure\Security\SecretKeyLoader;
use PHPUnit\Framework\TestCase;

final class SecretKeyLoaderPathResolutionTest extends TestCase
{
    public function testSecretKeyLoaderResolvesParentProjectFallback(): void
    {
        $baseDir = sys_get_temp_dir() . '/easywi-secret-loader-' . bin2hex(random_bytes(5));
        $projectDir = $baseDir . '/core';
        $parentKeyPath = $baseDir . '/var/easywi/secret.key';

        mkdir($projectDir, 0777, true);
        mkdir(dirname($parentKeyPath), 0777, true);
        file_put_contents($parentKeyPath, base64_encode(random_bytes(32)));

        try {
            $loader = new SecretKeyLoader(null, $projectDir);
            self::assertSame($parentKeyPath, $loader->getKeyPath());
            self::assertTrue($loader->isReadable());
        } finally {
            @unlink($parentKeyPath);
            @rmdir(dirname($parentKeyPath));
            @rmdir($baseDir . '/var');
            @rmdir($projectDir);
            @rmdir($baseDir);
        }
    }

    public function testEncryptionKeyLoaderResolvesParentProjectFallback(): void
    {
        $baseDir = sys_get_temp_dir() . '/easywi-encryption-loader-' . bin2hex(random_bytes(5));
        $projectDir = $baseDir . '/core';
        $parentKeyPath = $baseDir . '/var/easywi/secret.key';

        mkdir($projectDir, 0777, true);
        mkdir(dirname($parentKeyPath), 0777, true);
        file_put_contents($parentKeyPath, 'v1:' . base64_encode(random_bytes(32)));

        try {
            $loader = new EncryptionKeyLoader(null, $projectDir);
            self::assertSame($parentKeyPath, $loader->getKeyPath());
            self::assertTrue($loader->isReadable());
        } finally {
            @unlink($parentKeyPath);
            @rmdir(dirname($parentKeyPath));
            @rmdir($baseDir . '/var');
            @rmdir($projectDir);
            @rmdir($baseDir);
        }
    }
}
