<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Infrastructure\Config\DbConfigProvider;
use App\Infrastructure\Security\CryptoService;
use App\Infrastructure\Security\SecretKeyLoader;
use PHPUnit\Framework\TestCase;

final class DbConfigProviderPathResolutionTest extends TestCase
{
    public function testPrefersReadableSystemPathWhenPresent(): void
    {
        $provider = $this->createProvider(null, '/tmp');

        if (!is_file('/etc/easywi/db.json')) {
            $this->markTestSkipped('/etc/easywi/db.json is not present in this environment.');
        }

        self::assertSame('/etc/easywi/db.json', $provider->getConfigPath());
    }

    public function testResolvesParentProjectFallbackPathWhenFileExists(): void
    {
        $baseDir = sys_get_temp_dir() . '/easywi-db-provider-' . bin2hex(random_bytes(5));
        $projectDir = $baseDir . '/core';
        $parentFallbackPath = $baseDir . '/var/easywi/db.json';

        mkdir($projectDir, 0777, true);
        mkdir(dirname($parentFallbackPath), 0777, true);
        file_put_contents($parentFallbackPath, '{}');

        try {
            $provider = $this->createProvider(null, $projectDir);
            self::assertSame($parentFallbackPath, $provider->getConfigPath());
        } finally {
            @unlink($parentFallbackPath);
            @rmdir(dirname($parentFallbackPath));
            @rmdir($baseDir . '/var');
            @rmdir($projectDir);
            @rmdir($baseDir);
        }
    }

    private function createProvider(?string $envPath, string $projectDir): DbConfigProvider
    {
        $secretKeyLoader = new SecretKeyLoader('/tmp/easywi-test-secret.key', '/tmp');
        $cryptoService = new CryptoService($secretKeyLoader);

        return new DbConfigProvider($cryptoService, $secretKeyLoader, $envPath, $projectDir);
    }
}
