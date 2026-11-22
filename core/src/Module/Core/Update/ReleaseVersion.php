<?php

declare(strict_types=1);

namespace App\Module\Core\Update;

final class ReleaseVersion
{
    private const DEV_MARKER = '/(^|[._\-+])(?:predev|dev|alpha|snapshot|nightly)(?=$|[._\-+])/i';
    private const BETA_MARKER = '/(?:^|[._\-+])(?:beta|preview|rc)(?=$|[._\-+])/i';

    public static function normalize(?string $version): ?string
    {
        if ($version === null) {
            return null;
        }

        $value = ltrim(trim($version), 'vV');
        $value = preg_replace('/[^0-9A-Za-z.+-]/', '', $value) ?? $value;

        return $value !== '' ? $value : null;
    }

    /** @return 'dev'|'beta'|null */
    public static function prereleaseChannel(string $tag): ?string
    {
        if (preg_match(self::DEV_MARKER, $tag) === 1) {
            return 'dev';
        }

        return preg_match(self::BETA_MARKER, $tag) === 1 ? 'beta' : null;
    }

    public static function compare(?string $left, ?string $right): ?int
    {
        $leftVersion = self::comparisonVersion($left);
        $rightVersion = self::comparisonVersion($right);
        if ($leftVersion === null || $rightVersion === null) {
            return null;
        }

        return version_compare($leftVersion, $rightVersion);
    }

    private static function comparisonVersion(?string $tag): ?string
    {
        $version = self::normalize($tag);
        if ($version === null) {
            return null;
        }

        $version = preg_replace('/^agent-v(?=\d)/i', '', $version) ?? $version;

        return preg_replace(self::DEV_MARKER, '$1dev', $version) ?? $version;
    }
}
