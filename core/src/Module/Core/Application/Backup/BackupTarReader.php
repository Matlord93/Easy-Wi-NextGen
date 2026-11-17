<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup;

/** Bounded streaming extraction of an exact allowlist into private staging only. */
final class BackupTarReader
{
    private const int BLOCK_BYTES = 512;
    private const int COPY_BYTES = 1048576;

    public function __construct(private readonly ?BackupOperationHeartbeat $heartbeat = null)
    {
    }

    /**
     * @param array<string, ?int> $allowed Required flat member names => maximum bytes (null: unbounded).
     * @return array<string, string> Verified SHA-256 digests of extracted members.
     */
    public function extractFiles(string $archive, string $destinationDirectory, array $allowed, ?BackupOperationHeartbeat $heartbeat = null): array
    {
        $heartbeat ??= $this->heartbeat;
        $directory = realpath($destinationDirectory);
        if ($directory === false || !is_dir($directory) || is_link($destinationDirectory) || ((int) fileperms($directory) & 0077) !== 0
            || is_link($archive) || !is_file($archive) || str_contains($archive, '://') || str_contains($archive, "\0") || $allowed === []) {
            throw new \InvalidArgumentException('Tar extraction requires a regular local archive, private staging directory and explicit members.');
        }
        foreach ($allowed as $name => $maximum) {
            $this->assertFlatName($name);
            if ($maximum !== null && $maximum < 0) {
                throw new \InvalidArgumentException('Tar entry size limits cannot be negative.');
            }
        }
        $input = @fopen($archive, 'rb');
        if ($input === false) {
            throw new \RuntimeException('Unable to read the local tar archive.');
        }
        $created = [];
        $digests = [];
        $complete = false;
        $longName = null;
        try {
            $heartbeat?->pulse(true);
            while (true) {
                $heartbeat?->pulse();
                $header = $this->read($input, self::BLOCK_BYTES);
                if ($header === str_repeat("\0", self::BLOCK_BYTES)) {
                    if ($longName !== null || $this->read($input, self::BLOCK_BYTES) !== $header) {
                        throw new \InvalidArgumentException('Tar end marker is incomplete.');
                    }
                    while (!feof($input)) {
                        $heartbeat?->pulse();
                        $trailing = fread($input, self::COPY_BYTES);
                        if ($trailing === false || strspn($trailing, "\0") !== strlen($trailing)) {
                            throw new \InvalidArgumentException('Unexpected data follows the tar end marker.');
                        }
                    }
                    break;
                }
                $this->validateHeader($header);
                $size = $this->number(substr($header, 124, 12));
                $type = $header[156];
                $name = $this->field(substr($header, 0, 100));
                $prefix = $this->field(substr($header, 345, 155));
                if ($prefix !== '') {
                    $name = $prefix.'/'.$name;
                }
                if ($type === 'L') {
                    if ($longName !== null || $size < 1 || $size > 65536) {
                        throw new \InvalidArgumentException('Invalid GNU tar long-name record.');
                    }
                    $longName = rtrim($this->read($input, $size), "\0");
                    $this->skipPadding($input, $size);
                    continue;
                }
                if ($type !== '0' && $type !== "\0") {
                    throw new \InvalidArgumentException('Tar links, directories, extension records and special files are forbidden.');
                }
                if ($longName !== null) {
                    $name = $longName;
                    $longName = null;
                }
                $this->assertFlatName($name);
                if (!array_key_exists($name, $allowed) || isset($digests[$name]) || ($allowed[$name] !== null && $size > $allowed[$name])) {
                    throw new \InvalidArgumentException('Unexpected, duplicate or oversized tar member.');
                }
                $path = $directory.'/'.$name;
                $output = @fopen($path, 'x+b');
                if ($output === false) {
                    throw new \RuntimeException('Tar staging member already exists or cannot be created.');
                }
                $created[] = $path;
                try {
                    if (!chmod($path, 0600)) {
                        throw new \RuntimeException('Unable to restrict extracted member permissions.');
                    }
                    $hash = hash_init('sha256');
                    $remaining = $size;
                    while ($remaining > 0) {
                        $heartbeat?->pulse();
                        $chunk = $this->read($input, min(self::COPY_BYTES, $remaining));
                        hash_update($hash, $chunk);
                        $offset = 0;
                        while ($offset < strlen($chunk)) {
                            $written = fwrite($output, substr($chunk, $offset));
                            if ($written === false || $written === 0) {
                                throw new \RuntimeException('Unable to persist extracted tar member.');
                            }
                            $offset += $written;
                        }
                        $remaining -= strlen($chunk);
                    }
                    if (!fflush($output)) {
                        throw new \RuntimeException('Unable to flush extracted tar member.');
                    }
                    $digests[$name] = hash_final($hash);
                } finally {
                    fclose($output);
                }
                $this->skipPadding($input, $size);
            }
            if (array_diff_key($allowed, $digests) !== []) {
                throw new \InvalidArgumentException('Tar archive is missing required members.');
            }
            $heartbeat?->pulse(true);
            $complete = true;
            return $digests;
        } finally {
            fclose($input);
            if (!$complete) {
                foreach ($created as $path) {
                    @unlink($path);
                }
            }
        }
    }

    private function assertFlatName(string $name): void
    {
        if ($name === '' || $name === '.' || $name === '..' || strpbrk($name, "/\\\0") !== false || preg_match('/[\x00-\x1f\x7f]/', $name)) {
            throw new \InvalidArgumentException('Only explicitly named flat regular tar members are allowed.');
        }
    }

    private function validateHeader(string $header): void
    {
        if (!in_array(substr($header, 257, 6), ["ustar\0", 'ustar '], true)) {
            throw new \InvalidArgumentException('Unsupported tar header format.');
        }
        $expected = $this->number(substr($header, 148, 8));
        $checksumHeader = substr_replace($header, str_repeat(' ', 8), 148, 8);
        $actual = 0;
        for ($index = 0; $index < self::BLOCK_BYTES; ++$index) {
            $actual += ord($checksumHeader[$index]);
        }
        if ($actual !== $expected) {
            throw new \InvalidArgumentException('Tar header checksum verification failed.');
        }
    }

    private function field(string $field): string
    {
        $value = rtrim($field, "\0");
        if (str_contains($value, "\0")) {
            throw new \InvalidArgumentException('Invalid embedded NUL in tar header field.');
        }
        return $value;
    }

    private function number(string $field): int
    {
        if ((ord($field[0]) & 128) !== 0) {
            if ((ord($field[0]) & 64) !== 0) {
                throw new \InvalidArgumentException('Negative tar sizes are forbidden.');
            }
            $field[0] = chr(ord($field[0]) & 127);
            $value = 0;
            for ($index = 0; $index < strlen($field); ++$index) {
                $byte = ord($field[$index]);
                if ($value > intdiv(PHP_INT_MAX - $byte, 256)) {
                    throw new \InvalidArgumentException('Tar numeric field exceeds the platform integer limit.');
                }
                $value = $value * 256 + $byte;
            }
            return $value;
        }
        $octal = trim($field, " \0");
        if ($octal === '' || !preg_match('/^[0-7]+$/D', $octal)) {
            throw new \InvalidArgumentException('Invalid octal tar numeric field.');
        }
        $value = 0;
        for ($index = 0; $index < strlen($octal); ++$index) {
            $digit = ord($octal[$index]) - ord('0');
            if ($value > intdiv(PHP_INT_MAX - $digit, 8)) {
                throw new \InvalidArgumentException('Tar numeric field exceeds the platform integer limit.');
            }
            $value = $value * 8 + $digit;
        }
        return $value;
    }

    /** @param resource $input */
    private function read($input, int $bytes): string
    {
        $data = '';
        while (strlen($data) < $bytes) {
            $chunk = fread($input, $bytes - strlen($data));
            if ($chunk === false || $chunk === '') {
                throw new \RuntimeException('Tar archive is truncated.');
            }
            $data .= $chunk;
        }
        return $data;
    }

    /** @param resource $input */
    private function skipPadding($input, int $size): void
    {
        $padding = (self::BLOCK_BYTES - $size % self::BLOCK_BYTES) % self::BLOCK_BYTES;
        if ($padding !== 0 && $this->read($input, $padding) !== str_repeat("\0", $padding)) {
            throw new \InvalidArgumentException('Nonzero tar member padding is forbidden.');
        }
    }
}
