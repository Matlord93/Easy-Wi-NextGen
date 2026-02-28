<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

final class PortalLocale
{
    public const DEFAULT = 'de';

    /** @var list<string> */
    public const SUPPORTED = ['de', 'en'];

    public static function normalize(mixed $locale): ?string
    {
        if (!is_string($locale)) {
            return null;
        }

        $normalized = strtolower(trim($locale));

        return in_array($normalized, self::SUPPORTED, true) ? $normalized : null;
    }
}
