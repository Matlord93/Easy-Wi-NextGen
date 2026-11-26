<?php

declare(strict_types=1);

namespace App\Module\Nodes\Application;

final readonly class VmRootPassword
{
    public const string HASH_PATTERN = '#^\$6\$[a-f0-9]{16}\$[A-Za-z0-9./]{86}$#D';

    private function __construct(public string $password, public string $hash)
    {
    }

    public static function prepare(#[\SensitiveParameter] string $password = '', #[\SensitiveParameter] string $confirmation = ''): self
    {
        if ('' === $password && '' === $confirmation) {
            $password = bin2hex(random_bytes(24));
        } elseif ($password !== $confirmation || strlen($password) < 12 || strlen($password) > 128 || 1 === preg_match('/[\x00-\x1f\x7f]/', $password)) {
            throw new \InvalidArgumentException('vm_config.root_password_invalid');
        }
        $hash = crypt($password, '$6$'.bin2hex(random_bytes(8)).'$');
        if (!preg_match(self::HASH_PATTERN, $hash)) {
            throw new \RuntimeException('SHA-512 crypt is unavailable.');
        }

        return new self($password, $hash);
    }
}
