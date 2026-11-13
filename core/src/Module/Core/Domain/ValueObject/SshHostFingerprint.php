<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\ValueObject;

final class SshHostFingerprint
{
    public static function normalize(string $fingerprint): string
    {
        $fingerprint = trim($fingerprint);
        if (!preg_match('/^SHA256:([A-Za-z0-9+\/]{43}=?)$/D', $fingerprint, $matches)) {
            throw new \InvalidArgumentException('An OpenSSH SHA256 host fingerprint is required.');
        }
        $digest = base64_decode($matches[1], true);
        if (false === $digest || 32 !== strlen($digest)) {
            throw new \InvalidArgumentException('Invalid SHA256 host fingerprint.');
        }

        return 'SHA256:'.rtrim(base64_encode($digest), '=');
    }
}
