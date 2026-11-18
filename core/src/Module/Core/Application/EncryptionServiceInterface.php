<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

interface EncryptionServiceInterface
{
    /**
     * @return array{key_id: string, nonce: string, ciphertext: string}
     */
    public function encrypt(string $plaintext): array;

    /**
     * @param array<array-key, mixed> $payload
     */
    public function decrypt(array $payload): string;
}
