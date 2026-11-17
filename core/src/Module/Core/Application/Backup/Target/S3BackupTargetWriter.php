<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup\Target;

use App\Module\Core\Application\Backup\BackupOperationHeartbeat;
use App\Module\Core\Application\Backup\BackupRun;
use App\Module\Core\Application\Backup\BackupStorageTarget;
use App\Module\Core\Application\Backup\RemoteArchiveProof;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** S3 Signature V4, with streaming transfers and conditional object deletion. */
final class S3BackupTargetWriter implements BackupArchiveStorageInterface
{
    private const ALGORITHM = 'AWS4-HMAC-SHA256';

    public function __construct(private readonly HttpClientInterface $httpClient, private readonly ?BackupOperationHeartbeat $lease = null)
    {
    }

    #[\Override]
    public function supports(BackupStorageTarget $target): bool
    {
        return 's3' === $target->type();
    }

    #[\Override]
    public function write(BackupStorageTarget $target, string $archiveName, string $sourceFile): string
    {
        self::assertName($archiveName);
        if (!is_file($sourceFile) || !is_readable($sourceFile)) {
            throw new \InvalidArgumentException('Backup source file is not readable.');
        }
        $this->lease?->pulse(true);
        $hash = VerifiedArchiveFile::inspect($sourceFile, function (): void { $this->lease?->pulse(); })->sha256;
        $handle = fopen($sourceFile, 'rb');
        if (!is_resource($handle)) {
            throw new \RuntimeException('Cannot open S3 backup upload.');
        }
        try {
            $response = $this->request($target, 'PUT', $archiveName, $hash, ['content-type' => 'application/octet-stream'], $handle);
            if (!in_array($response->getStatusCode(), [200, 201, 204], true)) {
                throw new \RuntimeException('S3 upload failed with status '.$response->getStatusCode().'.');
            }
        } finally {
            fclose($handle);
        }
        $this->lease?->pulse(true);

        return $this->location($target, $archiveName);
    }

    #[\Override]
    public function inspect(BackupStorageTarget $target, string $location, callable $heartbeat): RemoteArchiveProof
    {
        $name = $this->nameAt($target, $location);
        $heartbeat();
        $response = $this->request($target, 'GET', $name, hash('sha256', ''));
        if (200 !== $response->getStatusCode()) {
            throw new \RuntimeException('S3 archive cannot be read for retention verification.');
        }
        $etag = $response->getHeaders(false)['etag'][0] ?? '';
        if (1 !== preg_match('/^"[^"\r\n]+"$/D', $etag)) {
            throw new \RuntimeException('S3 retention requires a strong ETag.');
        }
        $hash = hash_init('sha256');
        $size = 0;
        foreach ($this->httpClient->stream($response) as $chunk) {
            $heartbeat();
            if ($chunk->isTimeout()) {
                throw new \RuntimeException('S3 archive verification timed out.');
            }
            $content = $chunk->getContent();
            $size += strlen($content);
            hash_update($hash, $content);
        }

        return new RemoteArchiveProof($size, hash_final($hash), $etag);
    }

    #[\Override]
    public function delete(BackupStorageTarget $target, BackupRun $run, RemoteArchiveProof $proof, callable $heartbeat): void
    {
        $name = $this->nameAt($target, $run->archivePath());
        if ($proof->size !== $run->sizeBytes() || !hash_equals($run->checksumSha256(), $proof->sha256)
            || 1 !== preg_match('/^"[^"\r\n]+"$/D', $proof->version)) {
            throw new \RuntimeException('S3 deletion requires a matching verified archive.');
        }
        $heartbeat();
        $response = $this->request($target, 'DELETE', $name, hash('sha256', ''), ['if-match' => $proof->version]);
        if (!in_array($response->getStatusCode(), [200, 204], true)) {
            throw new \RuntimeException('S3 conditional deletion failed; archive inventory retained.');
        }
    }

    /**
     * @param array<string,string> $headers
     * @param resource|null        $body
     */
    private function request(BackupStorageTarget $target, string $method, string $name, string $hash, array $headers = [], mixed $body = null): ResponseInterface
    {
        $bucket = self::text($target->config()['bucket'] ?? '');
        $region = self::text($target->config()['region'] ?? '') ?: 'us-east-1';
        $endpoint = self::text($target->config()['endpoint'] ?? '');
        $accessKey = self::text($target->secrets()['access_key'] ?? '');
        $secretKey = self::text($target->secrets()['secret_key'] ?? '');
        if ('' === $bucket || '' === $accessKey || '' === $secretKey) {
            throw new \InvalidArgumentException('S3 target requires bucket, region and access credentials.');
        }
        if (1 !== preg_match('/^[a-z0-9][a-z0-9.-]*$/D', $bucket) || 1 !== preg_match('/^[a-z0-9-]+$/D', $region)) {
            throw new \InvalidArgumentException('Invalid S3 bucket or region.');
        }
        $key = $this->objectKey($target, $name);
        $customEndpoint = '' !== $endpoint;
        if (!$customEndpoint) {
            $endpoint = 'https://'.$bucket.'.s3.'.$region.'.amazonaws.com';
        }
        $parsed = S3BackupEndpoint::parse($endpoint);
        $endpoint = $parsed['origin'];
        $path = $parsed['base_path'].($customEndpoint ? '/'.rawurlencode($bucket) : '').'/'.$this->encodePath($key);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $headers['host'] = $parsed['authority'];
        $headers['x-amz-content-sha256'] = $hash;
        $headers['x-amz-date'] = $now->format('Ymd\THis\Z');
        $sessionToken = self::text($target->secrets()['session_token'] ?? '');
        if ('' !== $sessionToken) {
            $headers['x-amz-security-token'] = $sessionToken;
        }
        ksort($headers);
        $canonicalHeaders = '';
        foreach ($headers as $key => $value) {
            if (str_contains($value, "\r") || str_contains($value, "\n")) {
                throw new \InvalidArgumentException('Invalid S3 request header.');
            }
            $canonicalHeaders .= $key.':'.trim($value)."\n";
        }
        $signedHeaders = implode(';', array_keys($headers));
        $canonical = implode("\n", [$method, $path, '', $canonicalHeaders, $signedHeaders, $hash]);
        $date = $now->format('Ymd');
        $scope = $date.'/'.$region.'/s3/aws4_request';
        $stringToSign = implode("\n", [self::ALGORITHM, $headers['x-amz-date'], $scope, hash('sha256', $canonical)]);
        $signingKey = hash_hmac('sha256', $date, 'AWS4'.$secretKey, true);
        foreach ([$region, 's3', 'aws4_request'] as $part) {
            $signingKey = hash_hmac('sha256', $part, $signingKey, true);
        }
        $headers['authorization'] = self::ALGORITHM.' Credential='.$accessKey.'/'.$scope.', SignedHeaders='.$signedHeaders.', Signature='.hash_hmac('sha256', $stringToSign, $signingKey);
        $options = ['headers' => $headers, 'verify_peer' => true, 'verify_host' => true, 'max_redirects' => 0, 'buffer' => false,
            'on_progress' => function (): void { $this->lease?->pulse(); }];
        if (null !== $body) {
            $options['body'] = $body;
        }

        return $this->httpClient->request($method, $endpoint.$path, $options);
    }

    private function location(BackupStorageTarget $target, string $name): string
    {
        return 's3://'.self::text($target->config()['bucket'] ?? '').'/'.$this->objectKey($target, $name);
    }

    private function objectKey(BackupStorageTarget $target, string $name): string
    {
        self::assertName($name);
        $prefix = trim(self::text($target->config()['prefix'] ?? ''), '/');
        foreach (explode('/', $prefix) as $segment) {
            if ('.' === $segment || '..' === $segment || str_contains($segment, "\0") || str_contains($segment, '\\')) {
                throw new \InvalidArgumentException('Invalid S3 target prefix.');
            }
        }

        return ('' === $prefix ? '' : $prefix.'/').$name;
    }

    private function nameAt(BackupStorageTarget $target, string $location): string
    {
        $name = basename($location);
        if ($location !== $this->location($target, $name)) {
            throw new \RuntimeException('S3 archive is outside the configured target prefix.');
        }

        return $name;
    }

    private function encodePath(string $path): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', $path)));
    }

    private static function assertName(string $name): void
    {
        if ('' === $name || '.' === $name || '..' === $name || false !== strpbrk($name, "/\\\0\r\n")) {
            throw new \InvalidArgumentException('Invalid backup archive name.');
        }
    }

    private static function text(mixed $value): string
    {
        if (null === $value || is_scalar($value)) {
            return (string) $value;
        }

        throw new \InvalidArgumentException('Backup target field must be scalar.');
    }
}
