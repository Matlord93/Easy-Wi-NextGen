<?php

declare(strict_types=1);

namespace App\Tests\Module\Setup\Application;

use App\Module\Setup\Application\InstallEnvKeyGenerator;
use PHPUnit\Framework\TestCase;

final class InstallEnvKeyGeneratorTest extends TestCase
{
    public function testGeneratesAppSecret(): void
    {
        $generator = new InstallEnvKeyGenerator();

        $secret = $generator->generateAppSecret();

        self::assertSame(64, strlen($secret));
        self::assertMatchesRegularExpression('/^[a-f0-9]+$/', $secret);
    }

    public function testGeneratesEncryptionKeysetWithKeyId(): void
    {
        $generator = new InstallEnvKeyGenerator();

        $payload = $generator->generateEncryptionKeyset();

        self::assertSame('v1', $payload['active_key_id']);
        self::assertStringStartsWith('v1:', $payload['keyset']);
        [$keyId, $encoded] = explode(':', $payload['keyset'], 2);
        self::assertSame('v1', $keyId);
        self::assertSame(32, strlen((string) base64_decode($encoded, true)));
    }
}
