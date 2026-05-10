<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

class TokenGenerator
{
    public function __construct(private readonly EncryptionService $encryptionService)
    {
    }

    /**
     * @return array{token: string, token_hash: string, token_prefix: string, encrypted_token: array{key_id: string, nonce: string, ciphertext: string}}
     */
    public function generate(int $bytes = 32): array
    {
        return $this->fromToken(bin2hex(random_bytes($bytes)));
    }

    /**
     * @return array{token: string, token_hash: string, token_prefix: string, encrypted_token: array{key_id: string, nonce: string, ciphertext: string}}
     */
    public function fromToken(string $token): array
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
