<?php

declare(strict_types=1);

namespace App\Module\Nodes\Application;

final readonly class VmRootPassword
{
    public const string HASH_PATTERN = '#^\$6\$[a-f0-9]{16}\$[A-Za-z0-9./]{86}$#D';

    private function __construct(public string $password, public string $hash)
    {
    }

    public static function generate(): self
    {
        $password = bin2hex(random_bytes(24));
        $hash = crypt($password, '$6$'.bin2hex(random_bytes(8)).'$');
        if (!preg_match(self::HASH_PATTERN, $hash)) {
            throw new \RuntimeException('SHA-512 crypt is unavailable.');
        }

        return new self($password, $hash);
    }
}
