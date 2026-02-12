<?php

declare(strict_types=1);

namespace App\Tests\Module\Setup\Application;

use App\Module\Setup\Application\KeyMaterialGenerator;
use PHPUnit\Framework\TestCase;

final class KeyMaterialGeneratorTest extends TestCase
{
    public function testGenerateAppSecret(): void
    {
        $generator = new KeyMaterialGenerator();
        $secret = $generator->generateAppSecret();

        self::assertSame(64, strlen($secret));
        self::assertMatchesRegularExpression('/^[a-f0-9]+$/', $secret);
    }

    public function testGenerateEncryptionKeyset(): void
    {
        $generator = new KeyMaterialGenerator();

        $keyset = $generator->generateEncryptionKeyset();

        self::assertSame('v1', $keyset['activeKid']);
        self::assertArrayHasKey('v1', $keyset['keys']);
        self::assertSame(32, strlen((string) base64_decode($keyset['keys']['v1'], true)));
        self::assertSame('v1:' . $keyset['keys']['v1'], $generator->buildCsvKeyset($keyset['keys']));
    }
}
