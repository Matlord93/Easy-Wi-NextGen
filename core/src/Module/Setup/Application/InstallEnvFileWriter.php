<?php

declare(strict_types=1);

namespace App\Module\Setup\Application;

final class InstallEnvFileWriter
{
    /**
     * @param array<string, string> $values
     */
    public function ensureValues(string $targetFile, array $values): void
    {
        if ($values === []) {
            return;
        }

        $directory = dirname($targetFile);
        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new \RuntimeException('env_dir_not_writable');
        }

        $existing = is_file($targetFile) ? (string) file_get_contents($targetFile) : '';
        $existingKeys = $this->collectExistingKeys($existing);
        $missing = [];
        foreach ($values as $key => $value) {
            if (!isset($existingKeys[$key])) {
                $missing[$key] = $value;
            }
        }

        if ($missing === []) {
            return;
        }

        $content = rtrim($existing, "\n");
        if ($content !== '') {
            $content .= "\n";
        }

        foreach ($missing as $key => $value) {
            $content .= sprintf('%s=%s' . "\n", $key, $this->escapeValue($value));
        }

        if (file_put_contents($targetFile, $content) === false) {
            throw new \RuntimeException('env_write_failed');
        }

        @chmod($targetFile, 0600);
    }

    /**
     * @return array<string, true>
     */
    private function collectExistingKeys(string $content): array
    {
        $keys = [];
        foreach (explode("\n", $content) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key] = explode('=', $line, 2);
            $key = trim($key);
            if ($key !== '') {
                $keys[$key] = true;
            }
        }

        return $keys;
    }

    private function escapeValue(string $value): string
    {
        if ($value === '' || preg_match('/\s|#|"|\'|=|:/', $value) === 1) {
            return '"' . addcslashes($value, '\\"') . '"';
        }

        return $value;
    }
}
