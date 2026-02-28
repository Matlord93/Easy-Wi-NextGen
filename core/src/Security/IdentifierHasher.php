<?php

declare(strict_types=1);

namespace App\Security;

final class IdentifierHasher
{
    public function __construct(
        private readonly string $pepper,
    ) {
    }

    public function hash(string $identifier): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($identifier)), $this->pepper);
    }
}
