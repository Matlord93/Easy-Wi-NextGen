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
        if (!is_file($sourceFile) || !is_readable($sourceFile)) {
            throw new \InvalidArgumentException('Backup source file is not readable.');
        }

        $baseUrl = $this->baseUrl($target);
        UrlSafetyGuard::assertSafeHttpsEndpoint($baseUrl);

        $remotePath = $this->remotePath($target);
        $finalUrl = $this->buildUrl($baseUrl, $remotePath, $archiveName);
        $this->ensureCollections($target, $baseUrl, $remotePath);

        $handle = fopen($sourceFile, 'rb');
        if (!is_resource($handle)) {
            throw new \RuntimeException('Failed to open backup archive for WebDAV upload.');
        }

        try {
            $response = $this->httpClient->request('PUT', $finalUrl, $this->requestOptions($target, $handle));
            $statusCode = $response->getStatusCode();
        } finally {
            fclose($handle);
        }

        if (!in_array($statusCode, [200, 201, 204], true)) {
            throw new \RuntimeException($this->statusMessage('WebDAV upload failed', $statusCode));
        }

        return $finalUrl;
    }

    private function baseUrl(BackupStorageTarget $target): string
    {
        $baseUrl = rtrim((string) ($target->config()['url'] ?? ''), '/');
        if ($baseUrl === '') {
            throw new \InvalidArgumentException('WebDAV target requires url config.');
        }

        if ($target->type() === 'nextcloud' && !str_contains($baseUrl, '/remote.php/dav/files/')) {
            $username = $this->username($target);
            if ($username === '') {
                throw new \InvalidArgumentException('Nextcloud target requires username.');
            }
            $baseUrl .= '/remote.php/dav/files/'.rawurlencode($username);
        }

        return $baseUrl;
    }

    private function remotePath(BackupStorageTarget $target): string
    {
        $path = (string) ($target->config()['remote_path'] ?? $target->config()['root_path'] ?? '');
        $segments = [];
        foreach (explode('/', trim($path, '/')) as $segment) {
            $segment = trim($segment);
            if ($segment === '' || $segment === '.' || $segment === '..') {
                continue;
            }
            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    private function buildUrl(string $baseUrl, string $remotePath, string $fileName): string
    {
        $segments = [rtrim($baseUrl, '/')];
        foreach (explode('/', trim($remotePath, '/')) as $segment) {
            if ($segment !== '') {
                $segments[] = rawurlencode($segment);
            }
        }
        $segments[] = rawurlencode(basename($fileName));

        return implode('/', $segments);
    }

    private function ensureCollections(BackupStorageTarget $target, string $baseUrl, string $remotePath): void
    {
        $current = '';
        foreach (explode('/', trim($remotePath, '/')) as $segment) {
            if ($segment === '') {
                continue;
            }
            $current .= '/'.rawurlencode($segment);
            $response = $this->httpClient->request('MKCOL', rtrim($baseUrl, '/').$current, $this->requestOptions($target));
            $statusCode = $response->getStatusCode();
            if (!in_array($statusCode, [200, 201, 204, 405], true)) {
                throw new \RuntimeException($this->statusMessage('WebDAV collection creation failed', $statusCode));
            }
        }
    }

    /** @param resource|null $body */
    private function requestOptions(BackupStorageTarget $target, mixed $body = null): array
    {
        $verifyTls = (bool) ($target->config()['verify_tls'] ?? true);
        $options = [
            'headers' => $this->authHeaders($target),
            'verify_peer' => $verifyTls,
            'verify_host' => $verifyTls,
        ];

        if ($body !== null) {
            $options['body'] = $body;
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

            return $headers;
        }

        $password = $secrets['password'] ?? null;
        $username = $this->username($target);
        if ($username !== '' && is_string($password) && $password !== '') {
            $headers['Authorization'] = 'Basic '.base64_encode($username.':'.$password);
        }

        return $headers;
    }

    private function username(BackupStorageTarget $target): string
    {
        $secrets = $target->secrets();
        foreach ([$secrets['username'] ?? null, $target->config()['username'] ?? null] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return '';
    }

    private function statusMessage(string $prefix, int $statusCode): string
    {
        $reason = match ($statusCode) {
            401 => 'authentication failed',
            403 => 'access denied',
            404 => 'path not found',
            409 => 'parent collection missing or conflict',
            default => $statusCode >= 500 ? 'remote server error' : 'unexpected response',
        };

        return sprintf('%s with status %d (%s).', $prefix, $statusCode, $reason);
    }
}
