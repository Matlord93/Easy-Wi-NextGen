<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use Symfony\Component\Filesystem\Filesystem;

final class PrivateKeyFileReader
{
    public function __construct(private readonly string $root = '/etc/easywi')
    {
    }

    public function read(string $path): string
    {
        if (str_contains($path, "\0") || str_contains($path, '://')) {
            throw new \RuntimeException('SFTP key must be a local file under /etc/easywi.');
        }
        $root = realpath($this->root);
        $file = realpath($path);
        if ($root === false || $file === false || !str_starts_with($file, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
            || !is_file($file) || !is_readable($file)) {
            throw new \RuntimeException('SFTP key must be a readable file under /etc/easywi.');
        }

        return (new Filesystem())->readFile($file);
    }
}
