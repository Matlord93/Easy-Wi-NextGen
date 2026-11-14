<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Infrastructure\Config\DbConfigProvider;
use App\Infrastructure\Security\CryptoService;
use App\Infrastructure\Security\SecretKeyLoader;
use PHPUnit\Framework\TestCase;

final class DbConfigProviderPathResolutionTest extends TestCase
{
    public function testPrefersProjectFallbackPathOverSystemPathWhenBothExist(): void
    {
        $baseDir = sys_get_temp_dir() . '/easywi-db-provider-' . bin2hex(random_bytes(5));
        $projectDir = $baseDir . '/core';
        $projectFallbackPath = $projectDir . '/var/easywi/db.json';

        mkdir(dirname($projectFallbackPath), 0777, true);
        file_put_contents($projectFallbackPath, '{}');
        $systemPath = $baseDir . '/system-db.json';
        file_put_contents($systemPath, '{}');

        try {
            $provider = $this->createProvider(null, $projectDir, $systemPath);
            self::assertSame($projectFallbackPath, $provider->getConfigPath());
        } finally {
            @unlink($systemPath);
            @unlink($projectFallbackPath);
            @rmdir(dirname($projectFallbackPath));
            @rmdir($projectDir . '/var');
            @rmdir($projectDir);
            @rmdir($baseDir);
        }
    }

    public function testFallsBackToSystemPathWhenNoProjectConfigExists(): void
    {
        $baseDir = sys_get_temp_dir() . '/easywi-db-provider-' . bin2hex(random_bytes(5));
        mkdir($baseDir);
        $systemPath = $baseDir . '/system-db.json';
        file_put_contents($systemPath, '{}');

        try {
            $provider = $this->createProvider(null, $baseDir . '/core', $systemPath);
            self::assertSame($systemPath, $provider->getConfigPath());
        } finally {
            unlink($systemPath);
            rmdir($baseDir);
        }
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

    private function createProvider(?string $envPath, string $projectDir, string $systemPath = '/etc/easywi/db.json'): DbConfigProvider
    {
        $secretKeyLoader = new SecretKeyLoader('/tmp/easywi-test-secret.key', '/tmp');
        $cryptoService = new CryptoService($secretKeyLoader);

        return new DbConfigProvider($cryptoService, $secretKeyLoader, $envPath, $projectDir, $systemPath);
    }
}
