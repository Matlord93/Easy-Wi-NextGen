<?php

declare(strict_types=1);

namespace App\Module\Cms\Application;

final class CmsTemplateArchiveValidator
{
    private const ALLOWED_EXTENSIONS = [
        'twig', 'html', 'css', 'js', 'json', 'txt', 'md', 'svg', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'ico', 'avif',
        'woff', 'woff2', 'eot', 'ttf', 'otf', 'map',
    ];

    /**
     * @return array{file_count:int,uncompressed_size:int,entries:list<string>}
     */
    public function validate(string $zipPath, int $maxBytes = 25_000_000, int $maxFiles = 800): array
    {
        if (!is_file($zipPath)) {
            throw new \InvalidArgumentException('Template archive not found.');
        }

        $archiveSize = filesize($zipPath);
        if (!is_int($archiveSize) || $archiveSize <= 0 || $archiveSize > $maxBytes) {
            throw new \InvalidArgumentException('Template archive exceeds max upload size.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \InvalidArgumentException('Template archive is not a valid zip file.');
        }

        $entries = [];
        $uncompressedSize = 0;

        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $stat = $zip->statIndex($i);
            if (!is_array($stat) || !isset($stat['name'])) {
                continue;
            }

            $entry = $this->sanitizeEntryPath((string) $stat['name']);
            if ($entry === '' || str_ends_with($entry, '/')) {
                continue;
            }

            $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            if ($ext === '' || !in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
                throw new \InvalidArgumentException(sprintf('Disallowed template file type: %s', $entry));
            }

            $entries[] = $entry;
            $uncompressedSize += (int) ($stat['size'] ?? 0);

            if (count($entries) > $maxFiles) {
                throw new \InvalidArgumentException('Template archive contains too many files.');
            }

            if ($uncompressedSize > $maxBytes * 6) {
                throw new \InvalidArgumentException('Template archive uncompressed size is too large.');
            }
        }

        $zip->close();

        if ($entries === []) {
            throw new \InvalidArgumentException('Template archive contains no deployable files.');
        }

        return ['file_count' => count($entries), 'uncompressed_size' => $uncompressedSize, 'entries' => $entries];
    }

    private function sanitizeEntryPath(string $entry): string
    {
        $normalized = str_replace('\\', '/', trim($entry));
        $normalized = ltrim($normalized, '/');

        if ($normalized === '' || str_contains($normalized, "\0")) {
            return '';
        }

        $parts = explode('/', $normalized);
        foreach ($parts as $part) {
            if ($part === '.' || $part === '..') {
                throw new \InvalidArgumentException(sprintf('Unsafe archive entry path: %s', $entry));
            }
        }

        return implode('/', array_filter($parts, static fn (string $segment): bool => $segment !== ''));
    }
}
