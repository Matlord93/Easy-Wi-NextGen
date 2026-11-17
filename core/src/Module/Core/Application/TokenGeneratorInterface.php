<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

interface TokenGeneratorInterface
{
    /**
     * @return array{token: string, token_hash: string, token_prefix: string, encrypted_token: array{key_id:
     * string, nonce: string, ciphertext: string}}
     */
    public function generate(int $bytes = 32): array;

    /**
     * @return array{token: string, token_hash: string, token_prefix: string, encrypted_token:
     * array{key_id: string, nonce: string, ciphertext: string}}
     */
    public function fromToken(string $token): array;
}
