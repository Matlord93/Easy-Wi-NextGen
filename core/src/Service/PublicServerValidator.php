<?php

declare(strict_types=1);

namespace App\Service;

final class PublicServerValidator
{
    /**
     * @return string[]
     */
    public function validate(
        string $ip,
        ?int $port,
        ?int $queryPort,
        int $checkIntervalSeconds,
        bool $allowPrivateTargets,
    ): array {
        $errors = [];

        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $errors[] = 'IP address is invalid.';
        } elseif (!$allowPrivateTargets && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            $errors[] = 'Private or reserved IP ranges are not allowed.';
        }

        if ($port === null || $port < 1 || $port > 65535) {
            $errors[] = 'Port must be between 1 and 65535.';
        }

        if ($queryPort !== null && ($queryPort < 1 || $queryPort > 65535)) {
            $errors[] = 'Query port must be between 1 and 65535.';
        }

        if ($checkIntervalSeconds < 10) {
            $errors[] = 'Check interval must be at least 10 seconds.';
        }

        return $errors;
    }
}
