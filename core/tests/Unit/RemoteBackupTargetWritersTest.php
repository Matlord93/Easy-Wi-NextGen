<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Infrastructure\Security\PrivateKeyFileReader;
use App\Module\Core\Application\Backup\BackupRun;
use App\Module\Core\Application\Backup\BackupStorageTarget;
use App\Module\Core\Application\Backup\BackupTargetIdentity;
use App\Module\Core\Application\Backup\Target\NfsBackupTargetWriter;
use App\Module\Core\Application\Backup\Target\S3BackupTargetWriter;
use App\Module\Core\Application\Backup\Target\SftpBackupTargetWriter;
use App\Module\Core\Application\Backup\Target\VerifiedArchiveFile;
use App\Module\Core\Application\Backup\Target\WebDavBackupTargetWriter;
use App\Module\Core\Application\SftpConnectionFactory;
use App\Module\Core\Application\SshHostKeyVerifier;
use phpseclib3\Net\SFTP;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class RemoteBackupTargetWritersTest extends TestCase
{
    public function testS3UploadStreamsAndSignsCustomEndpointPathBucketEncodedKeyAndPort(): void
    {
        $source = tempnam(sys_get_temp_dir(), 's3-source-');
        self::assertIsString($source);
        file_put_contents($source, 'archive');
        try {
            $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
                self::assertSame('PUT', $method);
                self::assertSame('https://example.com:9443/storage/bucket/backup%20prefix/archive%201.tar', $url);
                self::assertIsResource($options['body']);
                self::assertSame('archive', stream_get_contents($options['body']));
                $headers = self::headers($options['headers']);
                self::assertSame('example.com:9443', $headers['host']);
                self::assertSame(hash('sha256', 'archive'), $headers['x-amz-content-sha256']);
                self::assertSame('session', $headers['x-amz-security-token']);
                $authorization = $headers['authorization'];
                unset($headers['authorization'], $headers['accept']);
                ksort($headers);
                $canonicalHeaders = '';
                foreach ($headers as $name => $value) {
                    $canonicalHeaders .= $name.':'.trim($value)."\n";
                }
                $signed = implode(';', array_keys($headers));
                $canonical = "PUT\n/storage/bucket/backup%20prefix/archive%201.tar\n\n".$canonicalHeaders."\n".$signed."\n".hash('sha256', 'archive');
                $date = substr($headers['x-amz-date'], 0, 8);
                $scope = $date.'/eu-central-1/s3/aws4_request';
                $key = hash_hmac('sha256', $date, 'AWS4secret', true);
                foreach (['eu-central-1', 's3', 'aws4_request'] as $part) {
                    $key = hash_hmac('sha256', $part, $key, true);
                }
                $signature = hash_hmac('sha256', "AWS4-HMAC-SHA256\n".$headers['x-amz-date']."\n".$scope."\n".hash('sha256', $canonical), $key);
                self::assertSame('AWS4-HMAC-SHA256 Credential=access/'.$scope.', SignedHeaders='.$signed.', Signature='.$signature, $authorization);
                self::assertSame(0, $options['max_redirects']);
                self::assertFalse($options['buffer']);

                return new MockResponse('', ['http_code' => 200]);
            });
            self::assertSame('s3://bucket/backup prefix/archive 1.tar', (new S3BackupTargetWriter($client))->write($this->s3Target(), 'archive 1.tar', $source));
        } finally {
            unlink($source);
        }
    }

    public function testS3InspectionStreamsAndDeletionSignsIfMatch(): void
    {
        $methods = [];
        $heartbeats = 0;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$methods): MockResponse {
            $methods[] = $method;
            self::assertSame('https://example.com:9443/storage/bucket/backup%20prefix/archive.tar', $url);
            if ('DELETE' === $method) {
                $headers = self::headers($options['headers']);
                self::assertSame('"etag-1"', $headers['if-match']);
                self::assertStringContainsString('SignedHeaders=host;if-match;x-amz-content-sha256;x-amz-date;x-amz-security-token', $headers['authorization']);

                return new MockResponse('', ['http_code' => 204]);
            }

            return new MockResponse((static function (): \Generator {
                yield 'arch';
                yield 'ive';
            })(), ['http_code' => 200, 'response_headers' => ['ETag: "etag-1"']]);
        });
        $writer = new S3BackupTargetWriter($client);
        $heartbeat = static function () use (&$heartbeats): void { ++$heartbeats; };
        $proof = $writer->inspect($this->s3Target(), 's3://bucket/backup prefix/archive.tar', $heartbeat);
        self::assertSame(7, $proof->size);
        self::assertSame(hash('sha256', 'archive'), $proof->sha256);
        $writer->delete($this->s3Target(), new BackupRun('run', 'plan', 'succeeded', 's3://bucket/backup prefix/archive.tar', 7, $proof->sha256, new \DateTimeImmutable()), $proof, $heartbeat);
        self::assertSame(['GET', 'DELETE'], $methods);
        self::assertGreaterThan(2, $heartbeats);
    }

    public function testS3LocationOutsideConfiguredBucketOrPrefixIsRejectedBeforeRequest(): void
    {
        $client = new MockHttpClient(static function (): MockResponse { self::fail('Out-of-target request.'); });
        $this->expectException(\RuntimeException::class);
        (new S3BackupTargetWriter($client))->inspect($this->s3Target(), 's3://other/backup prefix/archive.tar', static function (): void {});
    }

    public function testWebdavLocationOutsideConfiguredCollectionIsRejectedBeforeRequest(): void
    {
        $client = new MockHttpClient(static function (): MockResponse { self::fail('Out-of-target request.'); });
        $this->expectException(\RuntimeException::class);
        (new WebDavBackupTargetWriter($client))->inspect(new BackupStorageTarget('webdav', 'DAV', ['url' => 'https://example.com', 'remote_path' => 'backups']), 'https://example.com/other/archive.tar', static function (): void {});
    }

    public function testNfsRefusesUnmountedDirectoryInsteadOfWritingToLocalDisk(): void
    {
        $directory = sys_get_temp_dir().'/nfs-unmounted-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {
            try {
                (new NfsBackupTargetWriter())->write(new BackupStorageTarget('nfs', 'NFS', ['path' => $directory]), 'archive.tar', __FILE__);
                self::fail('An ordinary directory is not an NFS export.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('NFS', $error->getMessage());
            }
            self::assertFileDoesNotExist($directory.'/archive.tar');
        } finally {
            rmdir($directory);
        }
    }

    public function testSftpMissingFingerprintFailsBeforeConnecting(): void
    {
        $connections = $this->createMock(SftpConnectionFactory::class);
        $connections->expects(self::never())->method('create');
        $writer = new SftpBackupTargetWriter($connections, new SshHostKeyVerifier(), new PrivateKeyFileReader());
        $this->expectException(\App\Module\Core\Application\Exception\SftpException::class);
        $writer->inspect(new BackupStorageTarget('sftp', 'SFTP', ['host' => 'storage', 'path' => '/backups']), 'sftp://storage:22/backups/archive.tar', static function (): void {});
    }

    public function testSftpMismatchingHostKeyIsRejectedBeforeCredentials(): void
    {
        $connection = $this->createMock(SFTP::class);
        $connection->method('getServerPublicHostKey')->willReturn('ssh-ed25519 '.base64_encode('server-key'));
        $connection->expects(self::never())->method('login');
        $connections = $this->createStub(SftpConnectionFactory::class);
        $connections->method('create')->willReturn($connection);
        $writer = new SftpBackupTargetWriter($connections, new SshHostKeyVerifier(), new PrivateKeyFileReader());
        $target = new BackupStorageTarget('sftp', 'SFTP', ['host' => 'storage', 'path' => '/backups', 'host_key_fingerprint' => 'SHA256:'.rtrim(base64_encode(hash('sha256', 'different-key', true)), '=')], ['username' => 'backup', 'password' => 'secret']);
        $this->expectException(\App\Module\Core\Application\Exception\SftpException::class);
        $writer->inspect($target, 'sftp://storage:22/backups/archive.tar', static function (): void {});
    }

    public function testTargetIdentityBindsRootAndAccountButAllowsPasswordRotation(): void
    {
        $target = new BackupStorageTarget('webdav', 'DAV', ['url' => 'https://example.com', 'remote_path' => 'backups'], ['username' => 'owner', 'password' => 'old']);
        self::assertSame(BackupTargetIdentity::of($target), BackupTargetIdentity::of(new BackupStorageTarget('webdav', 'Other label', $target->config(), ['username' => 'owner', 'password' => 'new'])));
        self::assertNotSame(BackupTargetIdentity::of($target), BackupTargetIdentity::of(new BackupStorageTarget('webdav', 'DAV', $target->config(), ['username' => 'other', 'password' => 'old'])));
        self::assertNotSame(BackupTargetIdentity::of($target), BackupTargetIdentity::of(new BackupStorageTarget('webdav', 'DAV', ['url' => 'https://example.com', 'remote_path' => 'new'], $target->secrets())));
    }

    public function testFileReplacementDuringVerificationIsDetected(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'verify-race-');
        self::assertIsString($path);
        file_put_contents($path, 'original');
        $replaced = false;
        try {
            $this->expectException(\RuntimeException::class);
            VerifiedArchiveFile::inspect($path, static function () use ($path, &$replaced): void {
                if (!$replaced) {
                    unlink($path);
                    file_put_contents($path, 'replaced');
                    $replaced = true;
                }
            });
        } finally {
            unlink($path);
        }
    }

    private function s3Target(): BackupStorageTarget
    {
        return new BackupStorageTarget('s3', 'S3', ['endpoint' => 'https://example.com:9443/storage', 'bucket' => 'bucket', 'region' => 'eu-central-1', 'prefix' => 'backup prefix'], ['access_key' => 'access', 'secret_key' => 'secret', 'session_token' => 'session']);
    }

    private static function headers(array $lines): array
    {
        $result = [];
        foreach ($lines as $line) {
            [$name, $value] = explode(':', $line, 2);
            $result[strtolower($name)] = trim($value);
        }

        return $result;
    }
}
