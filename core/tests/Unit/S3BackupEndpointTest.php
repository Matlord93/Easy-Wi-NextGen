<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\Core\Application\Backup\BackupStorageTarget;
use App\Module\Core\Application\Backup\BackupTargetValidator;
use App\Module\Core\Application\Backup\Target\S3BackupEndpoint;
use App\Module\Core\Application\Backup\Target\S3BackupTargetWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class S3BackupEndpointTest extends TestCase
{
    #[DataProvider('configuredEndpoints')]
    public function testAdministratorSelectedInternalEndpointsKeepExactAuthorityAndBasePath(string $endpoint, string $origin, string $basePath): void
    {
        $target = self::target($endpoint);
        (new BackupTargetValidator())->validate($target);
        $parsed = S3BackupEndpoint::parse($endpoint);
        self::assertSame($origin, $parsed['origin']);
        self::assertSame($basePath, $parsed['base_path']);
        $requests = 0;
        $writer = new S3BackupTargetWriter(new MockHttpClient(static function (string $method, string $url, array $options) use ($origin, $basePath, $parsed, &$requests): MockResponse {
            ++$requests;
            self::assertSame('GET', $method);
            self::assertSame($origin.$basePath.'/backups/daily/archive.tar', $url);
            self::assertContains('host: '.$parsed['authority'], $options['headers']);
            self::assertTrue($options['verify_peer']);
            self::assertTrue($options['verify_host']);
            self::assertSame(0, $options['max_redirects']);

            return new MockResponse('archive', ['http_code' => 200, 'response_headers' => ['ETag: "revision"']]);
        }));
        $proof = $writer->inspect($target, 's3://backups/daily/archive.tar', static function (): void {});
        self::assertSame(hash('sha256', 'archive'), $proof->sha256);
        self::assertSame(1, $requests);
    }

    public static function configuredEndpoints(): iterable
    {
        yield 'internal MinIO hostname' => ['https://minio.internal:9000/storage', 'https://minio.internal:9000', '/storage'];
        yield 'unresolved setup hostname' => ['https://minio:9443/api', 'https://minio:9443', '/api'];
        yield '10 private network' => ['https://10.0.0.5:9443/api', 'https://10.0.0.5:9443', '/api'];
        yield '172 private network' => ['https://172.16.3.9:65535/storage/', 'https://172.16.3.9:65535', '/storage'];
        yield '192 private network and encoded base path' => ['https://192.168.4.2:9000/API%20prefix', 'https://192.168.4.2:9000', '/API%20prefix'];
        yield 'private IPv6' => ['https://[fd00::42]:9000/storage', 'https://[fd00::42]:9000', '/storage'];
        yield 'explicit local administrator endpoint' => ['https://localhost:9443', 'https://localhost:9443', ''];
        yield 'public endpoint' => ['https://example.com/s3', 'https://example.com', '/s3'];
    }

    #[DataProvider('invalidEndpoints')]
    public function testInsecureOrAmbiguousEndpointsAreRejectedByValidatorAndWriterBeforeNetwork(string $endpoint): void
    {
        try {
            (new BackupTargetValidator())->validate(self::target($endpoint));
            self::fail('Invalid endpoint accepted by validator.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('S3', $error->getMessage());
        }
        $requests = 0;
        $writer = new S3BackupTargetWriter(new MockHttpClient(static function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse('archive', ['response_headers' => ['ETag: "revision"']]);
        }));
        try {
            $writer->inspect(self::target($endpoint), 's3://backups/daily/archive.tar', static function (): void {});
            self::fail('Invalid endpoint accepted by writer.');
        } catch (\InvalidArgumentException) {
            self::assertSame(0, $requests);
        }
    }

    public static function invalidEndpoints(): iterable
    {
        foreach ([
            'http://minio.internal:9000/storage', '//minio.internal/storage', 'ftp://minio.internal/storage',
            'https://owner:secret@minio.internal/storage', 'https://owner@minio.internal/storage', 'https://@minio.internal/storage',
            'https://minio.internal/storage?target=elsewhere', 'https://minio.internal/storage?', 'https://minio.internal/storage#other',
            'https://minio.internal/a/../b', 'https://minio.internal/a/./b', 'https://minio.internal/a/%2e%2e/b',
            'https://minio.internal/a%2fb', 'https://minio.internal/a%5cb', 'https://minio.internal/a%00b', 'https://minio.internal/a%0d%0ab',
            'https://minio.internal/invalid%zz', 'https://-invalid.internal/storage', 'https://256.1.1.1/storage',
            'https://minio.internal:0/storage', 'https://minio.internal:65536/storage', 'https://minio.internal/has space',
            "https://minio.internal/storage\r\nInjected: value", 'https://minio.internal\\other/storage',
        ] as $endpoint) {
            yield $endpoint => [$endpoint];
        }
    }

    private static function target(string $endpoint): BackupStorageTarget
    {
        return new BackupStorageTarget('s3', 'Admin MinIO', ['endpoint' => $endpoint, 'bucket' => 'backups', 'prefix' => 'daily'], ['access_key' => 'admin-managed-key', 'secret_key' => 'secret']);
    }
}
