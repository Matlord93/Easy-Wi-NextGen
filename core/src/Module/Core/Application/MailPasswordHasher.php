<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

final class MailPasswordHasher
{
    public function __construct(private readonly string $algorithm = 'argon2id')
    {
    }

    public function hash(string $plainPassword): string
    {
        $scheme = strtolower(trim($this->algorithm));

        return match ($scheme) {
            'bcrypt' => $this->hashBcrypt($plainPassword),
            default => $this->hashArgon2id($plainPassword),
        };
    }

    private function hashArgon2id(string $plainPassword): string
    {
        $algorithm = defined('PASSWORD_ARGON2ID') ? constant('PASSWORD_ARGON2ID') : PASSWORD_BCRYPT;
        $hash = password_hash($plainPassword, $algorithm);
        if (!is_string($hash) || $hash === '') {
            throw new \RuntimeException('Failed to hash mailbox password with argon2id.');
        }

        return '{ARGON2ID}' . $hash;
    }

    private function hashBcrypt(string $plainPassword): string
    {
        $hash = password_hash($plainPassword, PASSWORD_BCRYPT);
        if (!is_string($hash) || $hash === '') {
            throw new \RuntimeException('Failed to hash mailbox password with bcrypt.');
        }

        return '{BLF-CRYPT}' . $hash;
    }
}
