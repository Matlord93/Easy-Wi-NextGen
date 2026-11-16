<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

final class MusicbotInputValue
{
    /** @return null|bool|int|float|string|array<array-key, mixed> */
    public static function from(mixed $value): null|bool|int|float|string|array
    {
        if (is_scalar($value) || is_array($value) || $value === null) {
            return $value;
        }

        throw new \InvalidArgumentException('Musicbot data must contain JSON-compatible values.');
    }
}
