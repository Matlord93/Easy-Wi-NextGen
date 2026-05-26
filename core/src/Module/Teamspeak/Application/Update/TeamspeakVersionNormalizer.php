<?php

declare(strict_types=1);

namespace App\Module\Teamspeak\Application\Update;

final class TeamspeakVersionNormalizer
{
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $v = trim($value);
        if ($v === '') {
            return null;
        }

        if (preg_match('/v?(\\d+\\.\\d+\\.\\d+(?:-[A-Za-z0-9.]+)?)/', $v, $m) !== 1) {
            return null;
        }

        return ltrim($m[1], 'v');
    }
}
