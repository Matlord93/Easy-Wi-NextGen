<?php

declare(strict_types=1);

namespace App\Tests\Module\Core\Application;

use App\Module\Core\Application\SecretsCrypto;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SecretsCryptoTest extends TestCase
{
    public function testDecryptsPayloadWithCurrentAppSecret(): void
    {
        $crypto = new SecretsCrypto('current-secret');
        $payload = $crypto->encrypt('stored-secret-value');

        self::assertSame('stored-secret-value', $crypto->decrypt($payload));
    }

    public function testDecryptsPayloadWithFallbackAppSecret(): void
    {
        $payload = (new SecretsCrypto('previous-secret'))->encrypt('stored-secret-value');
        $rotatedCrypto = new SecretsCrypto('current-secret', 'older-secret, previous-secret');

        self::assertSame('stored-secret-value', $rotatedCrypto->decrypt($payload));
    }

    public function testDecryptFailureMessageReferencesRuntimeEnvironment(): void
    {
        $payload = (new SecretsCrypto('previous-secret'))->encrypt('stored-secret-value');
        $rotatedCrypto = new SecretsCrypto('current-secret');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Ensure APP_SECRET from the active app environment is loaded at runtime or add prior secrets to APP_SECRET_FALLBACKS.');

        $rotatedCrypto->decrypt($payload);
    }
}
