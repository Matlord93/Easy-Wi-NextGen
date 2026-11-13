<?php

declare(strict_types=1);

namespace App\Infrastructure\Filesystem;

/** Local files below a caller-owned root, including not-yet-created targets. */
final class ConfinedLocalPath
{
    /**
     * Reject wrappers, UNC/network paths, traversal, alternate data streams and
     * symlink escapes before passing a path to PHP's filesystem functions.
     * The root is supplied by the owning resource/configuration, never a request.
     *
     * @psalm-taint-escape file
     * @psalm-taint-escape ssrf
     * @psalm-taint-escape url_component
     * @psalm-taint-escape url_path
     */
    public static function resolve(string $root, string $relative, bool $mustExist = false): ?string
    {
        $root = str_replace('\\', '/', $root);
        $relative = str_replace('\\', '/', $relative);
        if (preg_match('/[\x00-\x1f\x7f]/', $root.$relative) || str_starts_with($root, '//')) {
            return null;
        }
        $windows = '\\' === DIRECTORY_SEPARATOR;
        if ($windows ? 1 !== preg_match('#^[A-Za-z]:/[^:]*$#D', $root) : (!str_starts_with($root, '/') || str_contains($root, ':'))) {
            return null;
        }
        if (str_starts_with($relative, '/') || str_contains($relative, ':') || in_array('..', explode('/', $relative), true) || in_array('..', explode('/', $root), true)) {
            return null;
        }
        $base = self::canonical($root);
        $path = self::canonical(rtrim($root, '/').'/'.$relative);
        if (null === $base || '' === $base || null === $path || ($mustExist && !file_exists($path))) {
            return null;
        }
        $compareBase = rtrim($windows ? strtolower($base) : $base, '/');
        $comparePath = $windows ? strtolower($path) : $path;
        if ($comparePath !== $compareBase && !str_starts_with($comparePath, $compareBase.'/')) {
            return null;
        }

        return $path;
    }

    private static function canonical(string $path): ?string
    {
        $suffix = [];
        while (false === ($resolved = realpath($path))) {
            if (is_link($path) || dirname($path) === $path) {
                return null;
            }
            $suffix[] = basename($path);
            $path = dirname($path);
        }

        return rtrim(str_replace('\\', '/', $resolved), '/').([] === $suffix ? '' : '/'.implode('/', array_reverse($suffix)));
    }
}
