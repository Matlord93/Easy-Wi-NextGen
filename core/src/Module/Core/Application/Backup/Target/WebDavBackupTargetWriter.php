<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup\Target;

use App\Module\Core\Application\Backup\BackupOperationHeartbeat;
use App\Module\Core\Application\Backup\BackupRun;
use App\Module\Core\Application\Backup\BackupStorageTarget;
use App\Module\Core\Application\Backup\RemoteArchiveProof;
use App\Module\Core\Application\Backup\UrlSafetyGuard;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class WebDavBackupTargetWriter implements BackupArchiveStorageInterface
{
    public function __construct(private readonly HttpClientInterface $httpClient, private readonly ?BackupOperationHeartbeat $lease = null)
    {
    }

    #[\Override]
    public function supports(BackupStorageTarget $target): bool
    {
        return in_array($target->type(), ['webdav', 'nextcloud'], true);
    }

    #[\Override]
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
            $this->lease?->pulse(true);
            $response = $this->httpClient->request('PUT', $finalUrl, $this->requestOptions($target, $handle));
            $statusCode = $response->getStatusCode();
        } finally {
            fclose($handle);
        }

        if (!in_array($statusCode, [200, 201, 204], true)) {
            throw new \RuntimeException($this->statusMessage('WebDAV upload failed', $statusCode));
        }
        $this->lease?->pulse(true);

        return $finalUrl;
    }

    private function baseUrl(BackupStorageTarget $target): string
    {
        $baseUrl = rtrim(self::configText($target->config()['url'] ?? ''), '/');
        if ('' === $baseUrl) {
            throw new \InvalidArgumentException('WebDAV target requires url config.');
        }

        if ('nextcloud' === $target->type() && !str_contains($baseUrl, '/remote.php/dav/files/')) {
            $username = $this->username($target);
            if ('' === $username) {
                throw new \InvalidArgumentException('Nextcloud target requires username.');
            }
            $baseUrl .= '/remote.php/dav/files/'.rawurlencode($username);
        }

        return $baseUrl;
    }

    #[\Override]
    public function inspect(BackupStorageTarget $target, string $location, callable $heartbeat): RemoteArchiveProof
    {
        $this->assertArchiveLocation($target, $location);
        $heartbeat();
        $response = $this->httpClient->request('GET', $location, $this->requestOptions($target) + ['max_redirects' => 0]);
        if (200 !== $response->getStatusCode()) {
            throw new \RuntimeException('WebDAV archive cannot be read for retention verification.');
        }
        $etag = $response->getHeaders(false)['etag'][0] ?? '';
        if (1 !== preg_match('/^"[^"\r\n]+"$/D', $etag)) {
            throw new \RuntimeException('WebDAV retention requires a strong ETag for conditional deletion.');
        }
        $hash = hash_init('sha256');
        $size = 0;
        foreach ($this->httpClient->stream($response) as $chunk) {
            $heartbeat();
            if ($chunk->isTimeout()) {
                throw new \RuntimeException('WebDAV archive verification timed out.');
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
        $this->assertArchiveLocation($target, $run->archivePath());
        if ($proof->sha256 !== $run->checksumSha256() || $proof->size !== $run->sizeBytes() || '' === $proof->version) {
            throw new \RuntimeException('WebDAV deletion requires a matching verified archive.');
        }
        $heartbeat();
        $options = $this->requestOptions($target) + ['max_redirects' => 0];
        $options['headers']['If-Match'] = $proof->version;
        $response = $this->httpClient->request('DELETE', $run->archivePath(), $options);
        if (!in_array($response->getStatusCode(), [200, 204], true)) {
            throw new \RuntimeException('WebDAV conditional deletion failed; archive inventory retained.');
        }
    }

    private function assertArchiveLocation(BackupStorageTarget $target, string $location): void
    {
        $base = $this->baseUrl($target);
        UrlSafetyGuard::assertSafeHttpsEndpoint($base);
        $path = parse_url($location, PHP_URL_PATH);
        $name = is_string($path) ? rawurldecode(basename($path)) : '';
        if ('' === $name || '.' === $name || '..' === $name || str_contains($name, '/') || str_contains($name, '\\')
            || $location !== $this->buildUrl($base, $this->remotePath($target), $name)) {
            throw new \RuntimeException('WebDAV archive is outside the configured target collection.');
        }
    }

    private function remotePath(BackupStorageTarget $target): string
    {
        $path = self::configText($target->config()['remote_path'] ?? $target->config()['root_path'] ?? '');
        $segments = [];
        foreach (explode('/', trim($path, '/')) as $segment) {
            $segment = trim($segment);
            if ('' === $segment || '.' === $segment || '..' === $segment) {
                continue;
            }
            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    private function buildUrl(string $baseUrl, string $remotePath, string $fileName): string
    {
        $fileName = basename(str_replace('\\', '/', $fileName));
        if ('' === $fileName || '.' === $fileName || '..' === $fileName || false !== strpbrk($fileName, "\r\n\0")) {
            throw new \InvalidArgumentException('Invalid WebDAV archive name.');
        }
        $segments = [rtrim($baseUrl, '/')];
        foreach (explode('/', trim($remotePath, '/')) as $segment) {
            if ('' !== $segment) {
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
            if ('' === $segment) {
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

    /**
     * @param resource|null $body
     *
     * @return array{headers: array<string, string>, verify_peer: bool, verify_host: bool, max_redirects: int, buffer: bool, on_progress: \Closure, body?: resource}
     */
    private function requestOptions(BackupStorageTarget $target, mixed $body = null): array
    {
        $verifyTls = (bool) ($target->config()['verify_tls'] ?? true);
        $options = [
            'headers' => $this->authHeaders($target),
            'verify_peer' => $verifyTls,
            'verify_host' => $verifyTls,
            'max_redirects' => 0,
            'buffer' => false,
            'on_progress' => function (): void { $this->lease?->pulse(); },
        ];

        if (null !== $body) {
            $options['body'] = $body;
        }

        return $options;
    }

    /** @return array<string, string> */
    private function authHeaders(BackupStorageTarget $target): array
    {
        $headers = [];
        $secrets = $target->secrets();
        if (is_string($secrets['token'] ?? null) && '' !== $secrets['token']) {
            $headers['Authorization'] = 'Bearer '.self::configText($secrets['token']);

            return $headers;
        }

        $password = $secrets['password'] ?? null;
        $username = $this->username($target);
        if ('' !== $username && is_string($password) && '' !== $password) {
            $headers['Authorization'] = 'Basic '.base64_encode($username.':'.$password);
        }

        return $headers;
    }

    private function username(BackupStorageTarget $target): string
    {
        $secrets = $target->secrets();
        foreach ([$secrets['username'] ?? null, $target->config()['username'] ?? null] as $candidate) {
            if (is_string($candidate) && '' !== trim($candidate)) {
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

    private static function configText(mixed $value): string
    {
        if (null === $value || is_scalar($value)) {
            return (string) $value;
        }

        throw new \InvalidArgumentException('Backup target field must be scalar.');
    }
}
