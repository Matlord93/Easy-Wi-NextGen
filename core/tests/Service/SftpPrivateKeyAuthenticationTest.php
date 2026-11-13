<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Application\SftpFileService;
use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\EC;
use phpseclib3\Exception\NoKeyLoadedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SftpPrivateKeyAuthenticationTest extends TestCase
{
    #[DataProvider('passphrases')]
    public function testConfiguredPrivateKeyCanAuthenticate(?string $passphrase): void
    {
        $key = EC::createKey('Ed25519');
        $serialized = $key->withPassword($passphrase ?? false)->toString('PKCS8');

        $loaded = $this->resolve($serialized, $passphrase);

        self::assertInstanceOf(PrivateKey::class, $loaded);
        self::assertTrue($key->getPublicKey()->verify('sftp-authentication', $loaded->sign('sftp-authentication')));
    }

    public function testPublicKeyCannotBeUsedAsTheConfiguredPrivateKey(): void
    {
        $publicKey = EC::createKey('Ed25519')->getPublicKey()->toString('OpenSSH');
        $this->expectException(NoKeyLoadedException::class);

        $this->resolve($publicKey, null);
    }

    public function testMissingPrivateKeyLeavesPasswordAuthenticationAvailable(): void
    {
        self::assertNull($this->resolve(null, null));
    }

    public static function passphrases(): iterable
    {
        yield 'unencrypted' => [null];
        yield 'passphrase protected' => ['key-passphrase'];
    }

    private function resolve(?string $key, ?string $passphrase): ?PrivateKey
    {
        $settings = $this->createStub(AppSettingsService::class);
        $settings->method('getSftpPrivateKey')->willReturn($key);
        $settings->method('getSftpPrivateKeyPath')->willReturn(null);
        $settings->method('getSftpPrivateKeyPassphrase')->willReturn($passphrase);
        $service = (new \ReflectionClass(SftpFileService::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($service, 'settingsService'))->setValue($service, $settings);

        return (new \ReflectionMethod($service, 'resolveAuthentication'))->invoke($service);
    }
}
