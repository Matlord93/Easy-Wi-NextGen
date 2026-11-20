<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

final class InstanceBaseDirectory
{
    public static function normalize(string $path): string
    {
        $path = trim($path);
        if (preg_match('~^[a-zA-Z]:[\\\\/]~', $path) || str_starts_with($path, '\\\\')) {
            $path = str_replace('/', '\\', $path);
        }
        if (preg_match('/[\\x00-\\x1f\\x7f]/', $path) || preg_match('~(?:^|[/\\\\])\\.\\.?(?:$|[/\\\\])~', $path) || (!str_starts_with($path, '/') && !preg_match('~^[a-zA-Z]:\\\\~', $path) && !str_starts_with($path, '\\\\'))) {
            throw new \InvalidArgumentException('Das Basisverzeichnis für Instanzen muss ein absoluter Pfad ohne Punktsegmente sein, z. B. /srv/games oder C:\\EasyWI.');
        }

        return $path;
    }
}
