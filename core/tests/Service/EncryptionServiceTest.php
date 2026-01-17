<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Module\Core\Application\EncryptionService;
use PHPUnit\Framework\TestCase;

final class EncryptionServiceTest extends TestCase
{
    public function testEncryptDecryptRoundTrip(): void
    {
        if (!function_exists('sodium_crypto_aead_aes256gcm_encrypt')) {
            self::markTestSkipped('AES256-GCM not available in libsodium.');
        }

        $key = random_bytes(SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES);
        $service = new EncryptionService('v1', 'v1:' . base64_encode($key));

        $payload = $service->encrypt('super-secret');

        self::assertSame('v1', $payload['key_id']);
        self::assertNotSame('', $payload['nonce']);
        self::assertNotSame('', $payload['ciphertext']);
        self::assertSame('super-secret', $service->decrypt($payload));
    }

    public function testDecryptFailsWithUnknownKey(): void
    {
        if (!function_exists('sodium_crypto_aead_aes256gcm_encrypt')) {
            self::markTestSkipped('AES256-GCM not available in libsodium.');
        }

        $key = random_bytes(SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES);
        $service = new EncryptionService('v1', 'v1:' . base64_encode($key));
        $payload = $service->encrypt('super-secret');

        $otherService = new EncryptionService('v2', 'v2:' . base64_encode($key));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Encryption key "v1" is not available.');
        $otherService->decrypt($payload);
    }
}
