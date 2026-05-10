<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Module\Core\Application\EncryptionService;
use App\Module\Core\Application\TokenGenerator;
use PHPUnit\Framework\TestCase;

final class TokenGeneratorTest extends TestCase
{
    public function testFromTokenReturnsHashPrefixAndEncryptedPayload(): void
    {
        $encryption = $this->createMock(EncryptionService::class);
        $encryption
            ->expects(self::once())
            ->method('encrypt')
            ->with('0123456789abcdef')
            ->willReturn([
                'key_id' => 'v1',
                'nonce' => 'nonce',
                'ciphertext' => 'ciphertext',
            ]);

        $generator = new TokenGenerator($encryption);
        $result = $generator->fromToken('0123456789abcdef');

        self::assertSame('0123456789abcdef', $result['token']);
        self::assertSame(hash('sha256', '0123456789abcdef'), $result['token_hash']);
        self::assertSame('0123456789ab', $result['token_prefix']);
        self::assertSame([
            'key_id' => 'v1',
            'nonce' => 'nonce',
            'ciphertext' => 'ciphertext',
        ], $result['encrypted_token']);
    }
}
