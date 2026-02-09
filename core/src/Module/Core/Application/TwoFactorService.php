<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use RuntimeException;

final class TwoFactorService
{
    private const TIME_STEP = 30;
    private const CODE_LENGTH = 6;
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(int $bytes = 20): string
    {
        $random = random_bytes($bytes);
        return $this->base32Encode($random);
    }

    public function getOtpAuthUri(string $issuer, string $account, string $secret): string
    {
        $label = rawurlencode(sprintf('%s:%s', $issuer, $account));
        $issuerEncoded = rawurlencode($issuer);

        return sprintf(
            'otpauth://totp/%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            $label,
            $secret,
            $issuerEncoded,
            self::CODE_LENGTH,
            self::TIME_STEP,
        );
    }

    public function verifyCode(string $secret, string $code, int $window = 1, ?int $timestamp = null): bool
    {
        $code = preg_replace('/\s+/', '', $code);
        if ($code === null || $code === '' || !ctype_digit($code)) {
            return false;
        }

        $time = $timestamp ?? time();
        $counter = intdiv($time, self::TIME_STEP);

        for ($offset = -$window; $offset <= $window; $offset++) {
            if (hash_equals($this->calculateCode($secret, $counter + $offset), str_pad($code, self::CODE_LENGTH, '0', STR_PAD_LEFT))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string[]
     */
    public function generateRecoveryCodes(int $count = 10): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = sprintf('%04d-%04d', random_int(0, 9999), random_int(0, 9999));
        }

        return $codes;
    }

    /**
     * @param string[] $codes
     *
     * @return string[]
     */
    public function hashRecoveryCodes(array $codes): array
    {
        return array_map(
            static fn (string $code): string => password_hash($code, PASSWORD_DEFAULT),
            $codes,
        );
    }

    /**
     * @param string[] $hashedCodes
     */
    public function verifyRecoveryCode(string $code, array $hashedCodes): ?int
    {
        foreach ($hashedCodes as $index => $hash) {
            if (password_verify($code, $hash)) {
                return $index;
            }
        }

        return null;
    }

    private function calculateCode(string $secret, int $counter): string
    {
        $key = $this->base32Decode($secret);
        $counterBytes = pack('N*', 0) . pack('N*', $counter);
        $hash = hash_hmac('sha1', $counterBytes, $key, true);
        $offset = ord($hash[19]) & 0xf;
        $binary = (ord($hash[$offset]) & 0x7f) << 24
            | (ord($hash[$offset + 1]) & 0xff) << 16
            | (ord($hash[$offset + 2]) & 0xff) << 8
            | (ord($hash[$offset + 3]) & 0xff);
        $otp = $binary % (10 ** self::CODE_LENGTH);

        return str_pad((string) $otp, self::CODE_LENGTH, '0', STR_PAD_LEFT);
    }

    private function base32Encode(string $data): string
    {
        $alphabet = self::BASE32_ALPHABET;
        $binary = '';
        $length = strlen($data);

        for ($i = 0; $i < $length; $i++) {
            $binary .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
        }

        $chunks = str_split($binary, 5);
        $encoded = '';

        foreach ($chunks as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }
            $encoded .= $alphabet[bindec($chunk)];
        }

        return $encoded;
    }

    private function base32Decode(string $data): string
    {
        $alphabet = self::BASE32_ALPHABET;
        $data = strtoupper($data);
        $data = rtrim($data, '=');
        $binary = '';

        $length = strlen($data);
        for ($i = 0; $i < $length; $i++) {
            $char = $data[$i];
            $index = strpos($alphabet, $char);
            if ($index === false) {
                throw new RuntimeException('Invalid base32 secret.');
            }
            $binary .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';
        foreach (str_split($binary, 8) as $byte) {
            if (strlen($byte) < 8) {
                continue;
            }
            $bytes .= chr(bindec($byte));
        }

        return $bytes;
    }
}
