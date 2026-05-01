<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup\Target;

use App\Module\Core\Application\Backup\BackupStorageTarget;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Uploads backup archives to an S3-compatible object storage (AWS S3, MinIO, Hetzner Object Storage, etc.)
 * using AWS Signature Version 4 — no external SDK required.
 *
 * Required target config keys:
 *   bucket   – bucket name
 *   region   – AWS region (e.g. "eu-central-1") or empty string for path-style endpoints
 *   endpoint – (optional) custom endpoint URL for S3-compatible stores
 *   prefix   – (optional) key prefix / folder path inside the bucket
 *
 * Required target secrets keys:
 *   access_key – AWS access key ID
 *   secret_key – AWS secret access key
 */
final class S3BackupTargetWriter implements BackupTargetWriterInterface
{
    private const ALGORITHM = 'AWS4-HMAC-SHA256';

    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    public function supports(BackupStorageTarget $target): bool
    {
        return $target->type() === 's3';
    }

    public function write(BackupStorageTarget $target, string $archiveName, string $sourceFile): string
    {
        $bucket    = (string) ($target->config()['bucket'] ?? '');
        $region    = (string) ($target->config()['region'] ?? 'us-east-1');
        $endpoint  = rtrim((string) ($target->config()['endpoint'] ?? ''), '/');
        $prefix    = trim((string) ($target->config()['prefix'] ?? ''), '/');
        $accessKey = (string) ($target->secrets()['access_key'] ?? '');
        $secretKey = (string) ($target->secrets()['secret_key'] ?? '');

        if ($bucket === '' || $accessKey === '' || $secretKey === '') {
            throw new \InvalidArgumentException('S3 backup target requires bucket, access_key and secret_key.');
        }

        $objectKey = $prefix !== '' ? $prefix.'/'.$archiveName : $archiveName;

        // Build the endpoint URL.
        if ($endpoint === '') {
            $host = sprintf('%s.s3.%s.amazonaws.com', $bucket, $region);
            $baseUrl = sprintf('https://%s', $host);
            $path = '/'.$objectKey;
        } else {
            // Path-style for custom endpoints (MinIO, Hetzner, etc.)
            $host = parse_url($endpoint, PHP_URL_HOST) ?? '';
            $baseUrl = $endpoint.'/'.$bucket;
            $path = '/'.$objectKey;
        }

        $body         = (string) file_get_contents($sourceFile);
        $contentHash  = hash('sha256', $body);
        $now          = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $date         = $now->format('Ymd');
        $dateTime     = $now->format('Ymd\THis\Z');
        $contentType  = 'application/octet-stream';

        $headers = [
            'Content-Type'        => $contentType,
            'Host'                => $host,
            'x-amz-content-sha256' => $contentHash,
            'x-amz-date'          => $dateTime,
        ];

        $authorization = $this->buildAuthorization(
            $secretKey, $accessKey, $region, $date, $dateTime,
            'PUT', $path, $headers, $contentHash,
        );
        $headers['Authorization'] = $authorization;

        $url = $baseUrl.$path;
        $response = $this->httpClient->request('PUT', $url, [
            'headers' => $headers,
            'body'    => $body,
            'verify_peer' => true,
            'verify_host' => true,
        ]);

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(sprintf('S3 upload failed with status %d: %s', $status, $response->getContent(false)));
        }

        return sprintf('s3://%s/%s', $bucket, $objectKey);
    }

    /**
     * @param array<string, string> $headers
     */
    private function buildAuthorization(
        string $secretKey,
        string $accessKey,
        string $region,
        string $date,
        string $dateTime,
        string $method,
        string $path,
        array $headers,
        string $contentHash,
    ): string {
        // 1. Canonical headers (sorted, lowercase keys).
        $canonicalHeaders = '';
        $signedHeaderNames = [];
        ksort($headers);
        foreach ($headers as $key => $value) {
            $lowerKey = strtolower($key);
            $canonicalHeaders .= $lowerKey.':'.trim($value)."\n";
            $signedHeaderNames[] = $lowerKey;
        }
        $signedHeaders = implode(';', $signedHeaderNames);

        // 2. Canonical request.
        $canonicalRequest = implode("\n", [
            $method,
            $path,
            '', // query string
            $canonicalHeaders,
            $signedHeaders,
            $contentHash,
        ]);

        // 3. String to sign.
        $scope        = implode('/', [$date, $region, 's3', 'aws4_request']);
        $stringToSign = implode("\n", [
            self::ALGORITHM,
            $dateTime,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);

        // 4. Signing key.
        $signingKey = $this->deriveSigningKey($secretKey, $date, $region, 's3');

        // 5. Signature.
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        return sprintf(
            '%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            self::ALGORITHM,
            $accessKey,
            $scope,
            $signedHeaders,
            $signature,
        );
    }

    private function deriveSigningKey(string $secretKey, string $date, string $region, string $service): string
    {
        $kDate    = hash_hmac('sha256', $date, 'AWS4'.$secretKey, true);
        $kRegion  = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);

        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }
}
