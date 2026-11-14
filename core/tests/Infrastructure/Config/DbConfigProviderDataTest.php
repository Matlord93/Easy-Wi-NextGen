<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Config;

use App\Infrastructure\Config\DbConfigProvider;
use App\Infrastructure\Security\CryptoService;
use App\Infrastructure\Security\SecretKeyLoader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class DbConfigProviderDataTest extends TestCase
{
    private string $directory;
    private string $key;
    private SecretKeyLoader $loader;
    private CryptoService $crypto;
    private DbConfigProvider $provider;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/easywi-config-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->key = random_bytes(32);
        file_put_contents($this->directory.'/secret.key', base64_encode($this->key));
        $this->loader = new SecretKeyLoader($this->directory.'/secret.key', $this->directory);
        $this->crypto = new CryptoService($this->loader);
        $this->provider = new DbConfigProvider($this->crypto, $this->loader, $this->directory.'/db.json', $this->directory);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testEncryptedConfigurationRoundTripsWithoutChangingValues(): void
    {
        $payload = ['host' => 'localhost', 'dbname' => 'test', 'user' => 'test', 'password' => 'synthetic-password', 'port' => '3306'];
        $this->provider->store($payload);

        self::assertSame($payload, $this->provider->load());
        self::assertSame(3306, $this->provider->toConnectionParams($this->provider->load())['port']);
        self::assertSame([], $this->provider->validate($this->provider->load()));
    }

    public function testOpenSslEnvelopeRetainsItsAuthenticationTag(): void
    {
        if (!function_exists('openssl_encrypt')) {
            self::markTestSkipped('OpenSSL is not available.');
        }
        $payload = ['host' => 'localhost', 'dbname' => 'test', 'user' => 'test'];
        $encrypted = (new \ReflectionMethod(CryptoService::class, 'encryptWithOpenSsl'))->invoke($this->crypto, json_encode($payload, JSON_THROW_ON_ERROR), $this->key);
        file_put_contents($this->directory.'/db.json', json_encode($encrypted, JSON_THROW_ON_ERROR));

        self::assertSame($payload, $this->provider->load());
    }

    public function testMalformedEncryptedFieldFailsAsAConfigurationError(): void
    {
        file_put_contents($this->directory.'/db.json', json_encode([
            'nonce' => base64_encode(random_bytes(24)),
            'ciphertext' => ['unexpected'],
        ], JSON_THROW_ON_ERROR));

        $this->expectException(\RuntimeException::class);
        $this->provider->load();
    }

    public function testLegacyNumericKeyIdsRemainReadable(): void
    {
        file_put_contents($this->directory.'/secret.key', json_encode(['keys' => [1 => base64_encode($this->key)]], JSON_THROW_ON_ERROR));

        self::assertSame($this->key, $this->loader->loadKey());
    }
}
