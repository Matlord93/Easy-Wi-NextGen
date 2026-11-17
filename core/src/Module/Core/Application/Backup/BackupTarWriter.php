<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup;

/** Streaming POSIX tar with GNU long-name records; no shell or whole-file buffering. */
final class BackupTarWriter
{
    private const int BLOCK_BYTES = 512;
    private const int COPY_BYTES = 1048576;

    public function __construct(private readonly ?BackupOperationHeartbeat $heartbeat = null)
    {
    }

    public function write(string $sourceDirectory, string $outputTar, ?BackupOperationHeartbeat $heartbeat = null, ?int $maximumMemberBytes = null): void
    {
        $root = realpath($sourceDirectory);
        $parent = realpath(dirname($outputTar));
        if ($root === false || !is_dir($root) || is_link($sourceDirectory) || $parent === false) {
            throw new \InvalidArgumentException('Tar creation requires a local directory and new local output file.');
        }
        $root = rtrim($root, DIRECTORY_SEPARATOR);
        $destination = $parent.DIRECTORY_SEPARATOR.basename($outputTar);
        if ($destination === $root || str_starts_with($destination, $root.DIRECTORY_SEPARATOR)) {
            throw new \InvalidArgumentException('Tar output must be outside the source directory.');
        }
        $entries = (static function () use ($root): \Generator {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $entry) {
                if (!$entry instanceof \SplFileInfo || $entry->isLink() || !$entry->isFile()) {
                    throw new \InvalidArgumentException('Backup source contains a link or special file.');
                }
                $path = $entry->getPathname();
                yield substr($path, strlen($root) + 1) => $path;
            }
        })();
        $this->writeEntries($entries, $outputTar, $heartbeat ?? $this->heartbeat, $maximumMemberBytes);
    }

    /** @param array<string, string> $entries Archive member name => existing local source path. */
    public function writeFiles(array $entries, string $outputTar, ?BackupOperationHeartbeat $heartbeat = null): void
    {
        $this->writeEntries($entries, $outputTar, $heartbeat ?? $this->heartbeat);
    }

    /** @param iterable<string, string> $entries */
    private function writeEntries(iterable $entries, string $outputTar, ?BackupOperationHeartbeat $heartbeat, ?int $maximumMemberBytes = null): void
    {
        $parent = realpath(dirname($outputTar));
        if ($parent === false || str_contains($outputTar, '://') || str_contains($outputTar, "\0")) {
            throw new \InvalidArgumentException('Tar output must be a new local file.');
        }
        $destination = $parent.DIRECTORY_SEPARATOR.basename($outputTar);
        $output = @fopen($destination, 'x+b');
        if ($output === false) {
            throw new \RuntimeException('Unable to create a new tar output file.');
        }
        $complete = false;
        try {
            if (!chmod($destination, 0600)) {
                throw new \RuntimeException('Unable to restrict tar output permissions.');
            }
            $heartbeat?->pulse(true);
            foreach ($entries as $name => $path) {
                $heartbeat?->pulse();
                if ($name === '' || str_starts_with($name, '/') || str_contains($name, "\0") || str_contains($name, '\\') || preg_match('~(?:^|/)\.\.?(?:/|$)~', $name)) {
                    throw new \InvalidArgumentException('Unsafe relative tar member path.');
                }
                if (str_contains($path, '://') || str_contains($path, "\0") || is_link($path) || !is_file($path)) {
                    throw new \InvalidArgumentException('Tar source must be a regular local file.');
                }
                $canonical = realpath($path);
                if ($canonical === false || $canonical === $destination) {
                    throw new \InvalidArgumentException('Tar source cannot be its own output.');
                }
                $input = @fopen($path, 'rb');
                if ($input === false) {
                    throw new \RuntimeException('Unable to read backup source file.');
                }
                try {
                    $stat = fstat($input);
                    $current = lstat($path);
                    if ($stat === false || $current === false || ($stat['mode'] & 0170000) !== 0100000
                        || ($current['mode'] & 0170000) !== 0100000 || $stat['dev'] !== $current['dev'] || $stat['ino'] !== $current['ino'] || realpath($path) !== $canonical) {
                        throw new \RuntimeException('Backup source changed or is not a regular file.');
                    }
                    $size = $stat['size'];
                    if ($maximumMemberBytes !== null && $size > $maximumMemberBytes) {
                        throw new \InvalidArgumentException('Backup member exceeds the supported restore-reader limit. No archive was published.');
                    }
                    $mtime = max(0, $stat['mtime']);
                    if (strlen($name) > 100) {
                        // Padding terminates the name without counting NUL in its size. This also
                        // avoids PharData including a counted terminal NUL in the member key.
                        $longName = $name;
                        $this->put($output, $this->header('././@LongLink', strlen($longName), $mtime, 0600, 'L'));
                        $this->put($output, $longName);
                        $this->padding($output, strlen($longName));
                    }
                    $this->put($output, $this->header(substr($name, 0, 100), $size, $mtime, $stat['mode'] & 0777));
                    $remaining = $size;
                    while ($remaining > 0) {
                        $heartbeat?->pulse();
                        $chunk = fread($input, min(self::COPY_BYTES, $remaining));
                        if ($chunk === false || $chunk === '') {
                            throw new \RuntimeException('Backup source was truncated during tar creation.');
                        }
                        $this->put($output, $chunk);
                        $remaining -= strlen($chunk);
                    }
                    $after = fstat($input);
                    if ($after === false || $after['size'] !== $size || $after['mtime'] !== $stat['mtime']) {
                        throw new \RuntimeException('Backup source changed during tar creation.');
                    }
                    $this->padding($output, $size);
                } finally {
                    fclose($input);
                }
            }
            $this->put($output, str_repeat("\0", 2 * self::BLOCK_BYTES));
            if (!fflush($output)) {
                throw new \RuntimeException('Unable to flush completed tar archive.');
            }
            $heartbeat?->pulse(true);
            $complete = true;
        } finally {
            fclose($output);
            if (!$complete) {
                @unlink($destination);
            }
        }
    }

    private function header(string $name, int $size, int $mtime, int $mode, string $type = '0'): string
    {
        $header = str_pad($name, 100, "\0").$this->octal($mode, 8).$this->octal(0, 8).$this->octal(0, 8)
            .$this->number($size, 12).$this->number($mtime, 12).str_repeat(' ', 8).$type.str_repeat("\0", 100)
            .($type === 'L' ? "ustar  \0" : "ustar\0".'00').str_repeat("\0", 32 + 32).$this->octal(0, 8).$this->octal(0, 8).str_repeat("\0", 155 + 12);
        $checksum = 0;
        for ($index = 0; $index < self::BLOCK_BYTES; ++$index) {
            $checksum += ord($header[$index]);
        }
        return substr_replace($header, str_pad(decoct($checksum), 6, '0', STR_PAD_LEFT)."\0 ", 148, 8);
    }

    private function octal(int $value, int $bytes): string
    {
        $encoded = decoct($value);
        if (strlen($encoded) >= $bytes) {
            throw new \InvalidArgumentException('Tar numeric field exceeds its octal range.');
        }
        return str_pad($encoded, $bytes - 1, '0', STR_PAD_LEFT)."\0";
    }

    /** GNU base-256 fields allow archives larger than the POSIX 8 GiB octal limit. */
    private function number(int $value, int $bytes): string
    {
        if ($value < 0) {
            throw new \InvalidArgumentException('Tar numeric fields cannot be negative.');
        }
        if (strlen(decoct($value)) < $bytes) {
            return $this->octal($value, $bytes);
        }
        $encoded = '';
        for ($index = 0; $index < $bytes; ++$index) {
            $encoded = chr($value & 255).$encoded;
            $value >>= 8;
        }
        if ($value !== 0) {
            throw new \InvalidArgumentException('Tar numeric field exceeds its binary range.');
        }
        $encoded[0] = chr(ord($encoded[0]) | 128);
        return $encoded;
    }

    /** @param resource $output */
    private function padding($output, int $size): void
    {
        $remainder = $size % self::BLOCK_BYTES;
        if ($remainder !== 0) {
            $this->put($output, str_repeat("\0", self::BLOCK_BYTES - $remainder));
        }
    }

    /** @param resource $output */
    private function put($output, string $data): void
    {
        $offset = 0;
        $length = strlen($data);
        while ($offset < $length) {
            $written = fwrite($output, substr($data, $offset));
            if ($written === false || $written === 0) {
                throw new \RuntimeException('Unable to write tar archive block.');
            }
            $offset += $written;
        }
    }
}
