<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\Core\Application\Backup\BackupStorageTarget;
use App\Module\Core\Application\Backup\Target\LocalBackupTargetWriter;
use App\Module\Core\Application\Backup\Target\WebDavBackupTargetWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class BackupTargetWritersTest extends TestCase
{
    public function testWebdavWriterCreatesCollectionsAndUploadsStream(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'backup-src-');
        self::assertIsString($source);
        file_put_contents($source, 'archive-body');

        $requests = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $body = $options['body'] ?? null;
            $requests[] = [
                $method,
                $url,
                is_resource($body) ? stream_get_contents($body) : null,
                self::headerValue($options, 'authorization'),
            ];

            return new MockResponse('', ['http_code' => $method === 'PUT' ? 201 : 201]);
        });

        $writer = new WebDavBackupTargetWriter($client);
        $remote = $writer->write(new BackupStorageTarget('webdav', 'DAV', [
            'url' => 'https://example.com/base',
            'remote_path' => '/foo/bar',
        ], [
            'username' => 'user',
            'password' => 'pass',
        ]), 'archive.tar.gz', $source);

        self::assertSame('https://example.com/base/foo/bar/archive.tar.gz', $remote);
        self::assertSame('MKCOL', $requests[0][0]);
        self::assertSame('https://example.com/base/foo', $requests[0][1]);
        self::assertSame('MKCOL', $requests[1][0]);
        self::assertSame('https://example.com/base/foo/bar', $requests[1][1]);
        self::assertSame('PUT', $requests[2][0]);
        self::assertSame('archive-body', $requests[2][2]);
        self::assertSame('Basic '.base64_encode('user:pass'), $requests[2][3]);
    }

    public function testWebdavWriterTreatsOnlySuccessfulPutStatusesAsSuccess(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'backup-src-');
        self::assertIsString($source);
        file_put_contents($source, 'archive-body');

        foreach ([200, 201, 204] as $statusCode) {
            $writer = new WebDavBackupTargetWriter(new MockHttpClient([
                new MockResponse('', ['http_code' => $statusCode]),
            ]));

            self::assertSame(
                'https://example.com/archive.tar.gz',
                $writer->write(new BackupStorageTarget('webdav', 'DAV', ['url' => 'https://example.com'], ['username' => 'u', 'password' => 'p']), 'archive.tar.gz', $source),
            );
        }
    }

    #[DataProvider('failingWebdavStatuses')]
    public function testWebdavWriterReportsAuthAndServerFailures(int $statusCode): void
    {
        $source = tempnam(sys_get_temp_dir(), 'backup-src-');
        self::assertIsString($source);
        file_put_contents($source, 'archive-body');

        $writer = new WebDavBackupTargetWriter(new MockHttpClient([
            new MockResponse('', ['http_code' => $statusCode]),
        ]));

        $this->expectException(\RuntimeException::class);
        $writer->write(new BackupStorageTarget('webdav', 'DAV', ['url' => 'https://example.com'], ['username' => 'u', 'password' => 'p']), 'archive.tar.gz', $source);
    }

    /** @return iterable<string, array{int}> */
    public static function failingWebdavStatuses(): iterable
    {
        yield 'unauthorized' => [401];
        yield 'forbidden' => [403];
        yield 'server-error' => [500];
    }

    public function testNextcloudWriterBuildsEncodedDavPath(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'backup-src-');
        self::assertIsString($source);
        file_put_contents($source, 'archive-body');

        $urls = [];
        $writer = new WebDavBackupTargetWriter(new MockHttpClient(function (string $method, string $url) use (&$urls): MockResponse {
            $urls[] = [$method, $url];
            return new MockResponse('', ['http_code' => 201]);
        }));

        $remote = $writer->write(new BackupStorageTarget('nextcloud', 'NC', [
            'url' => 'https://example.com',
            'remote_path' => '/Easy WI/Server Backups',
            'username' => 'john doe@example.test',
        ], [
            'password' => 'app-password',
        ]), 'archive 1.tar.gz', $source);

        self::assertSame('https://example.com/remote.php/dav/files/john%20doe%40example.test/Easy%20WI/Server%20Backups/archive%201.tar.gz', $remote);
        self::assertSame(['PUT', $remote], $urls[2]);
    }

    public function testLocalWriterWritesFileAndRejectsTraversal(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'backup-src-');
        self::assertIsString($source);
        file_put_contents($source, 'archive-body');

        $targetDir = sys_get_temp_dir().'/easywi-local-writer-'.bin2hex(random_bytes(4));
        $writer = new LocalBackupTargetWriter();
        $destination = $writer->write(new BackupStorageTarget('local', 'Local', ['base_path' => $targetDir]), 'archive.tar.gz', $source);

        self::assertFileExists($destination);
        self::assertSame('archive-body', file_get_contents($destination));

        $traversalDestination = $writer->write(new BackupStorageTarget('local', 'Local', ['base_path' => $targetDir]), '../evil.tar.gz', $source);
        self::assertSame($targetDir.'/evil.tar.gz', $traversalDestination);
        self::assertFileDoesNotExist(dirname($targetDir).'/evil.tar.gz');
    }

    private static function headerValue(array $options, string $name): ?string
    {
        $headers = $options['headers'] ?? [];
        if (!is_array($headers)) {
            return null;
        }

        $lowerName = strtolower($name);

        foreach ($headers as $headerName => $value) {
            if (is_int($headerName) && is_string($value)) {
                [$lineName, $lineValue] = array_pad(explode(':', $value, 2), 2, null);
                if ($lineValue !== null && strtolower($lineName) === $lowerName) {
                    return trim($lineValue);
                }

                continue;
            }

            if (!is_string($headerName) || strtolower($headerName) !== $lowerName) {
                continue;
            }

            if (is_array($value)) {
                $first = reset($value);

                return is_string($first) ? $first : null;
            }

            return is_string($value) ? $value : null;
        }

        return null;
    }
}
