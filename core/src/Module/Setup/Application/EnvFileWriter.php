<?php

declare(strict_types=1);

namespace App\Module\Setup\Application;

final class EnvFileWriter
{
    /**
     * @param array<string, string> $values
     * @param list<string> $sourceFiles
     *
     * @return list<string> keys that were written to target file
     */
    public function setMissingKeys(string $targetFile, array $values, array $sourceFiles = []): array
    {
        if ($values === []) {
            return [];
        }

        $directory = dirname($targetFile);
        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new \RuntimeException('env_dir_not_writable');
        }

        if (is_file($targetFile) && !is_readable($targetFile)) {
            throw new \RuntimeException('env_file_not_readable');
        }

        if (is_file($targetFile) && !is_writable($targetFile)) {
            throw new \RuntimeException('env_file_not_writable');
        }

        if (!is_file($targetFile) && !is_writable($directory)) {
            throw new \RuntimeException('env_dir_not_writable');
        }

        $existingKeys = $this->collectExistingKeys(array_merge($sourceFiles, [$targetFile]));
        $toWrite = [];
        foreach ($values as $key => $value) {
            if (isset($existingKeys[$key])) {
                continue;
            }
            $toWrite[$key] = $value;
        }

        if ($toWrite === []) {
            return [];
        }

        $existingContent = is_file($targetFile) ? (string) file_get_contents($targetFile) : '';
        $newContent = rtrim($existingContent, "\n");
        if ($newContent !== '') {
            $newContent .= "\n";
        }

        foreach ($toWrite as $key => $value) {
            $newContent .= sprintf('%s=%s' . "\n", $key, $this->escapeValue($value));
        }

        $this->atomicWrite($targetFile, $newContent);

        return array_keys($toWrite);
    }

    /**
     * @param list<string> $files
     *
     * @return array<string, true>
     */
    private function collectExistingKeys(array $files): array
    {
        $existing = [];

        foreach ($files as $file) {
            if (!is_file($file) || !is_readable($file)) {
                continue;
            }

            $lines = file($file, FILE_IGNORE_NEW_LINES);
            if (!is_array($lines)) {
                continue;
            }

            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }

                [$rawKey] = explode('=', $line, 2);
                $key = trim($rawKey);
                if ($key === '' || preg_match('/^[A-Z0-9_]+$/', $key) !== 1) {
                    continue;
                }

                $existing[$key] = true;
            }
        }

        return $existing;
    }

    private function atomicWrite(string $path, string $content): void
    {
        $directory = dirname($path);
        $tmp = tempnam($directory, '.env.local.tmp.');
        if ($tmp === false) {
            throw new \RuntimeException('env_write_failed');
        }

        try {
            if (file_put_contents($tmp, $content) === false) {
                throw new \RuntimeException('env_write_failed');
            }

            @chmod($tmp, 0600);

            if (!@rename($tmp, $path)) {
                throw new \RuntimeException('env_write_failed');
            }

            @chmod($path, 0600);
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    private function escapeValue(string $value): string
    {
        if ($value === '' || preg_match('/\s|#|"|\'|=/', $value) === 1) {
            return '"' . addcslashes($value, "\\\"") . '"';
        }

        return $value;
    }
}
