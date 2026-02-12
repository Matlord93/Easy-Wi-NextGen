<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application\Status;

final class HmacRequestValidator
{
    public function __construct(
        private readonly string $sharedSecret,
        private readonly int $maxSkewSeconds,
    ) {
    }

    public function isValid(string $body, ?string $timestamp, ?string $signature): bool
    {
        if ($this->sharedSecret === '' || $timestamp === null || $signature === null) {
            return false;
        }

        if (!ctype_digit($timestamp)) {
            return false;
        }

        $ts = (int) $timestamp;
        if (abs(time() - $ts) > $this->maxSkewSeconds) {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', $body, $this->sharedSecret, true));

        return hash_equals($expected, $signature);
    }
}
