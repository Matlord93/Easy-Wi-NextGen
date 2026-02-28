<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup\Target;

use App\Module\Core\Application\Backup\BackupStorageTarget;
use App\Module\Core\Application\Backup\UrlSafetyGuard;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class WebDavBackupTargetWriter implements BackupTargetWriterInterface
{
    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    public function supports(BackupStorageTarget $target): bool
    {
        return in_array($target->type(), ['webdav', 'nextcloud'], true);
    }

    public function write(BackupStorageTarget $target, string $archiveName, string $sourceFile): string
    {
        $baseUrl = rtrim((string) ($target->config()['url'] ?? ''), '/');
        if ($baseUrl === '') {
            throw new \InvalidArgumentException('WebDAV target requires url config.');
        }

        UrlSafetyGuard::assertSafeHttpsEndpoint($baseUrl);

        $rootPath = trim((string) ($target->config()['root_path'] ?? ''), '/');
        $tmpName = sprintf('.upload-%s-%s.tmp', bin2hex(random_bytes(4)), $archiveName);

        $tempUrl = $this->buildUrl($baseUrl, $rootPath, $tmpName);
        $finalUrl = $this->buildUrl($baseUrl, $rootPath, $archiveName);
        $options = $this->requestOptions($target, $sourceFile);

        $response = $this->httpClient->request('PUT', $tempUrl, $options);
        if ($response->getStatusCode() >= 300) {
            throw new \RuntimeException('WebDAV temp upload failed with status '.$response->getStatusCode());
        }

        $move = $this->httpClient->request('MOVE', $tempUrl, [
            'headers' => $this->authHeaders($target) + [
                'Destination' => $finalUrl,
                'Overwrite' => 'T',
            ],
            'auth_basic' => $this->authBasic($target),
        ]);

        if ($move->getStatusCode() >= 300) {
            $this->tryDelete($tempUrl, $target);
            throw new \RuntimeException('WebDAV atomic move failed with status '.$move->getStatusCode());
        }

        $this->verifyUpload($finalUrl, $target, $sourceFile);

        return $finalUrl;
    }

    private function buildUrl(string $baseUrl, string $rootPath, string $fileName): string
    {
        $segments = [$baseUrl];
        if ($rootPath !== '') {
            $segments[] = trim($rootPath, '/');
        }
        $segments[] = ltrim($fileName, '/');

        return implode('/', $segments);
    }

    /** @return array<string, mixed> */
    private function requestOptions(BackupStorageTarget $target, string $sourceFile): array
    {
        $options = [
            'headers' => $this->authHeaders($target),
            'body' => fopen($sourceFile, 'rb'),
        ];

        $authBasic = $this->authBasic($target);
        if ($authBasic !== null) {
            $options['auth_basic'] = $authBasic;
        }

        return $options;
    }

    /** @return array<string, string> */
    private function authHeaders(BackupStorageTarget $target): array
    {
        $headers = [];
        $secrets = $target->secrets();
        if (is_string($secrets['token'] ?? null) && $secrets['token'] !== '') {
            $headers['Authorization'] = 'Bearer '.$secrets['token'];
        }

        return $headers;
    }

    /** @return array{string, string}|null */
    private function authBasic(BackupStorageTarget $target): ?array
    {
        $secrets = $target->secrets();
        if (is_string($secrets['username'] ?? null) && is_string($secrets['password'] ?? null)) {
            return [(string) $secrets['username'], (string) $secrets['password']];
        }

        return null;
    }

    private function verifyUpload(string $url, BackupStorageTarget $target, string $sourceFile): void
    {
        $response = $this->httpClient->request('HEAD', $url, [
            'headers' => $this->authHeaders($target),
            'auth_basic' => $this->authBasic($target),
        ]);

        if ($response->getStatusCode() >= 300) {
            throw new \RuntimeException('WebDAV verify failed with status '.$response->getStatusCode());
        }

        $headers = array_change_key_case($response->getHeaders(false), CASE_LOWER);
        $expectedSize = filesize($sourceFile);
        if (is_int($expectedSize) && isset($headers['content-length'][0])) {
            $remoteSize = (int) $headers['content-length'][0];
            if ($remoteSize !== $expectedSize) {
                throw new \RuntimeException(sprintf('WebDAV verify failed: expected size %d, got %d.', $expectedSize, $remoteSize));
            }
        }

        if (isset($headers['etag'][0]) && trim((string) $headers['etag'][0]) === '') {
            throw new \RuntimeException('WebDAV verify failed: empty ETag header.');
        }
    }

    private function tryDelete(string $url, BackupStorageTarget $target): void
    {
        $this->httpClient->request('DELETE', $url, [
            'headers' => $this->authHeaders($target),
            'auth_basic' => $this->authBasic($target),
        ]);
    }
}
