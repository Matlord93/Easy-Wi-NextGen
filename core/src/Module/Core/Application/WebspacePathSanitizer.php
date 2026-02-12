<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

final class WebspacePathSanitizer
{
    public function sanitizeRelativePath(string $value): string
    {
        $value = trim($value);
        if ($value === '' || $value === '.') {
            return '';
        }

        if (str_contains($value, "\0") || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new \InvalidArgumentException('invalid_path');
        }

        if (str_starts_with($value, '/')) {
            throw new \InvalidArgumentException('invalid_path');
        }

        $parts = explode('/', str_replace('\\', '/', $value));
        $safe = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                throw new \InvalidArgumentException('path_outside_webspace_root');
            }
            $safe[] = $part;
        }

        return implode('/', $safe);
    }
}
