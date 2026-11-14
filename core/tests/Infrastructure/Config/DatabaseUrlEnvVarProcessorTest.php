<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Config;

use App\Infrastructure\Config\DbConfigProvider;
use App\Infrastructure\Database\DatabaseNotConfiguredException;
use App\Infrastructure\Database\DatabaseUrlEnvVarProcessor;
use App\Infrastructure\Security\CryptoService;
use App\Infrastructure\Security\SecretKeyLoader;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class DatabaseUrlEnvVarProcessorTest extends TestCase
{
    private string $directory;
    private DbConfigProvider $provider;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/easywi-database-url-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        file_put_contents($this->directory.'/secret.key', base64_encode(random_bytes(32)));
        $loader = new SecretKeyLoader($this->directory.'/secret.key', $this->directory);
        $this->provider = new DbConfigProvider(new CryptoService($loader), $loader, $this->directory.'/db.json', $this->directory);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testUnconfiguredInstallerKeepsTheDefaultMysqlConnection(): void
    {
        self::assertSame('mysql://localhost?charset=utf8mb4', $this->url());
    }

    public function testEncryptedCredentialsSurviveTheRealDbalDsnParser(): void
    {
        $payload = ['host' => '::1', 'dbname' => 'tenant/panel', 'user' => 'user@example.test', 'password' => 'p@ss:/?#%&=+ ü', 'port' => 3307, 'serverVersion' => 'mariadb-10.11.0', 'charset' => 'utf8mb4'];
        $this->provider->store($payload);
        $parsed = (new DsnParser(['mysql' => 'pdo_mysql']))->parse($this->url());

        foreach ($payload as $key => $value) {
            self::assertSame((string) $value, (string) $parsed[$key]);
        }
        self::assertSame('pdo_mysql', $parsed['driver']);
    }

    public function testInvalidStoredConfigurationCannotCreateAConnection(): void
    {
        $this->provider->store(['host' => 'localhost']);
        $this->expectException(DatabaseNotConfiguredException::class);
        $this->url();
    }

    private function url(): string
    {
        return (new DatabaseUrlEnvVarProcessor($this->provider))->getEnv('easywi_database_url', '', static fn (string $name): never => throw new \LogicException('Unexpected fallback environment access: '.$name));
    }
}
