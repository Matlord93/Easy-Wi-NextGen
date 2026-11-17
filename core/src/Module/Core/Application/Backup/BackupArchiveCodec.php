<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup;

/** Streaming codec for the existing IV + AES-256-CBC backup file format. */
final class BackupArchiveCodec
{
    private const int CHUNK_SIZE = 1048576;

    public function __construct(private readonly ?BackupOperationHeartbeat $heartbeat = null)
    {
    }

    public function gzip(string $input, string $output): void
    {
        $context = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 9]);
        if ($context === false) {
            throw new \RuntimeException('Unable to initialize backup compression.');
        }
        $this->transform($input, $output, static function (string $chunk, bool $final) use ($context): string {
            $result = deflate_add($context, $chunk, $final ? ZLIB_FINISH : ZLIB_NO_FLUSH);
            if ($result === false) {
                throw new \RuntimeException('Backup compression failed.');
            }
            return $result;
        });
    }

    public function gunzip(string $input, string $output): void
    {
        $context = inflate_init(ZLIB_ENCODING_GZIP);
        if ($context === false) {
            throw new \RuntimeException('Unable to initialize backup decompression.');
        }
        $ended = false;
        $this->transform($input, $output, static function (string $chunk, bool $final) use ($context, &$ended): string {
            if ($ended) {
                if ($chunk !== '') {
                    throw new \RuntimeException('Unexpected trailing data in compressed backup.');
                }
                return '';
            }
            $readBefore = inflate_get_read_len($context);
            $result = @inflate_add($context, $chunk, $final ? ZLIB_FINISH : ZLIB_SYNC_FLUSH);
            if ($result === false) {
                throw new \RuntimeException('Invalid compressed backup.');
            }
            $ended = inflate_get_status($context) === ZLIB_STREAM_END;
            if ($ended && inflate_get_read_len($context) - $readBefore !== strlen($chunk)) {
                throw new \RuntimeException('Unexpected trailing data in compressed backup.');
            }
            if ($final && !$ended) {
                throw new \RuntimeException('Truncated compressed backup.');
            }
            return $result;
        });
    }

    public function isGzip(string $path): bool
    {
        $stream = $this->openInput($path);
        try {
            return fread($stream, 2) === "\x1f\x8b";
        } finally {
            fclose($stream);
        }
    }

    public function encrypt(string $input, string $output, string $key): void
    {
        $in = $this->openInput($input);
        try {
            $out = $this->openOutput($output);
            try {
                $iv = random_bytes(16);
                $this->writeAll($out, $iv);
                $derivedKey = hash('sha256', $key, true);
                $buffer = '';
                while (!feof($in)) {
                    $buffer .= $this->readChunk($in);
                    while (strlen($buffer) >= self::CHUNK_SIZE) {
                        $block = substr($buffer, 0, self::CHUNK_SIZE);
                        $buffer = substr($buffer, self::CHUNK_SIZE);
                        $ciphertext = openssl_encrypt($block, 'AES-256-CBC', $derivedKey, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
                        if (!is_string($ciphertext)) {
                            throw new \RuntimeException('Backup encryption failed.');
                        }
                        $iv = substr($ciphertext, -16);
                        $this->writeAll($out, $ciphertext);
                    }
                }
                $last = openssl_encrypt($buffer, 'AES-256-CBC', $derivedKey, OPENSSL_RAW_DATA, $iv);
                if (!is_string($last)) {
                    throw new \RuntimeException('Backup encryption failed.');
                }
                $this->writeAll($out, $last);
                $this->flush($out);
            } finally {
                fclose($out);
            }
        } finally {
            fclose($in);
        }
    }

    public function decrypt(string $input, string $output, string $key): void
    {
        $in = $this->openInput($input);
        try {
            $iv = fread($in, 16);
            if (!is_string($iv) || strlen($iv) !== 16) {
                throw new \RuntimeException('Truncated encrypted backup header.');
            }
            $out = $this->openOutput($output);
            try {
                $derivedKey = hash('sha256', $key, true);
                $buffer = '';
                while (!feof($in)) {
                    $buffer .= $this->readChunk($in);
                    while (strlen($buffer) > self::CHUNK_SIZE) {
                        $ciphertext = substr($buffer, 0, self::CHUNK_SIZE);
                        $buffer = substr($buffer, self::CHUNK_SIZE);
                        $plaintext = openssl_decrypt($ciphertext, 'AES-256-CBC', $derivedKey, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
                        if (!is_string($plaintext)) {
                            throw new \RuntimeException('Backup decryption failed.');
                        }
                        $iv = substr($ciphertext, -16);
                        $this->writeAll($out, $plaintext);
                    }
                }
                if ($buffer === '' || strlen($buffer) % 16 !== 0) {
                    throw new \RuntimeException('Truncated encrypted backup.');
                }
                $last = openssl_decrypt($buffer, 'AES-256-CBC', $derivedKey, OPENSSL_RAW_DATA, $iv);
                if (!is_string($last)) {
                    throw new \RuntimeException('Invalid encrypted backup or encryption key.');
                }
                $this->writeAll($out, $last);
                $this->flush($out);
            } finally {
                fclose($out);
            }
        } finally {
            fclose($in);
        }
    }

    /** @param callable(string, bool): string $transform */
    private function transform(string $input, string $output, callable $transform): void
    {
        $in = $this->openInput($input);
        try {
            $out = $this->openOutput($output);
            try {
                while (!feof($in)) {
                    $chunk = $this->readChunk($in);
                    if ($chunk !== '') {
                        $this->writeAll($out, $transform($chunk, false));
                    }
                }
                $this->writeAll($out, $transform('', true));
                $this->flush($out);
            } finally {
                fclose($out);
            }
        } finally {
            fclose($in);
        }
    }

    /** @return resource */
    private function openInput(string $path)
    {
        $stream = @fopen($path, 'rb');
        if (!is_resource($stream)) {
            throw new \RuntimeException('Unable to read backup stream.');
        }
        return $stream;
    }

    /** @return resource */
    private function openOutput(string $path)
    {
        $stream = @fopen($path, 'xb');
        if (!is_resource($stream)) {
            throw new \RuntimeException('Unable to create backup stream.');
        }
        if (!chmod($path, 0600)) {
            fclose($stream);
            throw new \RuntimeException('Unable to restrict backup stream permissions.');
        }
        return $stream;
    }

    /** @param resource $stream */
    private function readChunk($stream): string
    {
        $this->heartbeat?->pulse();
        $chunk = fread($stream, self::CHUNK_SIZE);
        if ($chunk === false || ($chunk === '' && !feof($stream))) {
            throw new \RuntimeException('Unable to read complete backup stream.');
        }
        return $chunk;
    }

    /** @param resource $stream */
    private function flush($stream): void
    {
        if (!fflush($stream)) {
            throw new \RuntimeException('Unable to flush complete backup stream.');
        }
    }

    /** @param resource $stream */
    private function writeAll($stream, string $bytes): void
    {
        $length = strlen($bytes);
        $offset = 0;
        while ($offset < $length) {
            $written = fwrite($stream, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new \RuntimeException('Unable to write complete backup stream.');
            }
            $offset += $written;
        }
    }
}
