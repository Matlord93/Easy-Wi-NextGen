<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

interface SecretsCryptoInterface
{
    public function encrypt(string $plaintext): string;

    public function decrypt(string $payload): string;
}
