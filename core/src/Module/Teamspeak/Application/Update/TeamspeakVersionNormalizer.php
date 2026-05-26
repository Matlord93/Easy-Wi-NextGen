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

        $v = (string) preg_replace('/\.(tar\.(gz|bz2|xz|zst)|tar|zip|tgz|tbz2|txz)$/i', '', $v);

        if (preg_match('/v?(\\d+\\.\\d+\\.\\d+(?:-[A-Za-z0-9.]+)?)/', $v, $m) !== 1) {
            return null;
        }

        return ltrim($m[1], 'v');
    }
}
