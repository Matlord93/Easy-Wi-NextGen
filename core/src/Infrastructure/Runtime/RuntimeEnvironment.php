<?php

declare(strict_types=1);

namespace App\Infrastructure\Runtime;

final class RuntimeEnvironment
{
    public static function name(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            throw new \InvalidArgumentException('APP_ENV must be a non-empty string.');
        }

        return $value;
    }
}
