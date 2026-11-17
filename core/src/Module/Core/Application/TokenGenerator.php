<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(TokenGeneratorInterface::class)]
final class TokenGenerator implements TokenGeneratorInterface
{
    public function __construct(private readonly EncryptionService $encryptionService)
    {
    }

    /**
     * @return array{token: string, token_hash: string, token_prefix: string, encrypted_token: array{key_id:
     * string, nonce: string, ciphertext: string}}
     */
    #[\Override]
    public function generate(int $bytes = 32): array
    {
        if ($bytes < 1) {
            throw new \ValueError('Token length must be greater than zero.');
        }

        return $this->fromToken(bin2hex(random_bytes($bytes)));
    }

    /**
     * @return array{token: string, token_hash: string, token_prefix: string, encrypted_token:
     * array{key_id: string, nonce: string, ciphertext: string}}
     */
    #[\Override]
    public function fromToken(string $token): array
    {
        $tokenHash = hash('sha256', $token);
        $tokenPrefix = substr($token, 0, 12);
        $encryptedToken = $this->encryptionService->encrypt($token);

        return [
            'token' => $token,
            'token_hash' => $tokenHash,
            'token_prefix' => $tokenPrefix,
            'encrypted_token' => $encryptedToken,
        ];
    }
}
