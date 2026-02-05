<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Application\Exception\FileServiceException;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Gameserver\Application\GameServerPathResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class FileServiceClient
{
    private const DEFAULT_EDITOR_LIMIT = 1_048_576;
    private const HEADER_AGENT_ID = 'X-Agent-ID';
    private const HEADER_CUSTOMER_ID = 'X-Customer-ID';
    private const HEADER_TIMESTAMP = 'X-Timestamp';
    private const HEADER_SIGNATURE = 'X-Signature';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly EncryptionService $encryptionService,
        private readonly int $timeoutSeconds,
        private readonly int $defaultPort,
        private readonly string $defaultScheme,
        private readonly string $clientCertPath,
        private readonly string $clientKeyPath,
        private readonly string $clientCaPath,
        private readonly LoggerInterface $logger,
        private readonly RequestStack $requestStack,
        private readonly GameServerPathResolver $gameServerPathResolver,
    ) {
    }

    /**
     * @return array{root_path: string, path: string, entries: array<int, array{name: string, size: int, mode: string, modified_at: string, is_dir: bool}>}
     */
    public function list(Instance $instance, string $path): array
    {
        $normalizedPath = $this->normalizeRelativePath($path);
        $endpoint = $this->buildEndpoint($instance, '/files');

        $payload = $this->requestJson($instance, 'GET', $endpoint, [
            'path' => $normalizedPath,
        ]);

        return [
            'root_path' => (string) ($payload['root_path'] ?? ''),
            'path' => (string) ($payload['path'] ?? ''),
            'entries' => is_array($payload['entries'] ?? null) ? $payload['entries'] : [],
        ];
    }

    public function readFile(Instance $instance, string $path, string $name, ?int $maxBytes = null): string
    {
        $this->assertValidName($name);
        $relative = $this->combinePath($path, $name);
        $endpoint = $this->buildEndpoint($instance, '/read');

        $response = $this->requestRaw($instance, 'GET', $endpoint, [
            'path' => $relative,
        ]);

        $maxBytes = $maxBytes ?? 0;
        if ($maxBytes > 0) {
            $length = $this->parseContentLength($response->getHeaders(false));
            if ($length !== null && $length > $maxBytes) {
                throw new \RuntimeException('File too large for editor.');
            }
        }

        $content = $response->getContent(false);
        if ($maxBytes > 0 && strlen($content) > $maxBytes) {
            throw new \RuntimeException('File too large for editor.');
        }

        return $content;
    }

    public function readFileForEditor(Instance $instance, string $path, string $name): string
    {
        return $this->readFile($instance, $path, $name, self::DEFAULT_EDITOR_LIMIT);
    }

    public function writeFile(Instance $instance, string $path, string $name, string $content): void
    {
        $this->assertValidName($name);
        $relative = $this->combinePath($path, $name);
        $endpoint = $this->buildEndpoint($instance, '/write');

        $this->requestJson($instance, 'POST', $endpoint, [], [
            'path' => $relative,
            'content' => $content,
        ]);
    }

    public function uploadFile(Instance $instance, string $path, UploadedFile $upload): void
    {
        $endpoint = $this->buildEndpoint($instance, '/upload');
        $relativePath = $this->normalizeRelativePath($path);
        $formData = new FormDataPart([
            'path' => $relativePath,
            'upload' => DataPart::fromPath($upload->getPathname(), $upload->getClientOriginalName()),
        ]);

        $this->requestMultipart($instance, $endpoint, $formData);
    }

    public function makeDirectory(Instance $instance, string $path, string $name): void
    {
        $this->assertValidName($name);
        $relative = $this->combinePath($path, $name);
        $endpoint = $this->buildEndpoint($instance, '/mkdir');

        $this->requestJson($instance, 'POST', $endpoint, [], [
            'path' => $relative,
        ]);
    }

    public function delete(Instance $instance, string $path, string $name): void
    {
        $this->assertValidName($name);
        $relative = $this->combinePath($path, $name);
        $endpoint = $this->buildEndpoint($instance, '/delete');

        $this->requestJson($instance, 'POST', $endpoint, [], [
            'path' => $relative,
        ]);
    }

    public function rename(Instance $instance, string $path, string $name, string $newName): void
    {
        $this->assertValidName($name);
        $this->assertValidName($newName);
        $relative = $this->combinePath($path, $name);
        $newRelative = $this->combinePath($path, $newName);
        $endpoint = $this->buildEndpoint($instance, '/rename');

        $this->requestJson($instance, 'POST', $endpoint, [], [
            'path' => $relative,
            'new_path' => $newRelative,
        ]);
    }

    public function chmod(Instance $instance, string $path, string $name, int $mode): void
    {
        $this->assertValidName($name);
        $relative = $this->combinePath($path, $name);
        $endpoint = $this->buildEndpoint($instance, '/chmod');

        $this->requestJson($instance, 'POST', $endpoint, [], [
            'path' => $relative,
            'mode' => $mode,
        ]);
    }

    public function extract(Instance $instance, string $path, string $name, string $destination): void
    {
        $this->assertValidName($name);
        $relative = $this->combinePath($path, $name);
        $endpoint = $this->buildEndpoint($instance, '/extract');

        $this->requestJson($instance, 'POST', $endpoint, [], [
            'path' => $relative,
            'destination' => $this->normalizeRelativePath($destination),
        ]);
    }

    public function downloadFile(Instance $instance, string $path, string $name): string
    {
        $this->assertValidName($name);
        $relative = $this->combinePath($path, $name);
        $endpoint = $this->buildEndpoint($instance, '/download');

        $response = $this->requestRaw($instance, 'GET', $endpoint, [
            'path' => $relative,
        ]);

        return $response->getContent(false);
    }

    /**
     * @return array{ok: bool, status_code: int|null, error: string|null, url: string}
     */
    public function ping(Instance $instance): array
    {
        $baseUrl = $this->getBaseUrlForInstance($instance);
        $healthUrl = $baseUrl . '/health';

        try {
            $this->assertTlsConfigured($baseUrl);
        } catch (FileServiceException $exception) {
            return [
                'ok' => false,
                'status_code' => $exception->getStatusCode(),
                'error' => $exception->getMessage(),
                'url' => $healthUrl,
            ];
        }

        $options = [
            'timeout' => min(5, $this->timeoutSeconds),
            'max_duration' => min(5, $this->timeoutSeconds),
        ];
        $tlsOptions = $this->buildTlsOptions();
        if ($tlsOptions !== []) {
            $options = array_merge($options, $tlsOptions);
        }

        try {
            $response = $this->httpClient->request('GET', $healthUrl, $options);
            $statusCode = $response->getStatusCode();
            $body = $response->getContent(false);
            $decoded = json_decode($body, true);
        } catch (TimeoutExceptionInterface $exception) {
            return [
                'ok' => false,
                'status_code' => null,
                'error' => 'timeout',
                'url' => $healthUrl,
                'body' => null,
            ];
        } catch (TransportExceptionInterface $exception) {
            return [
                'ok' => false,
                'status_code' => null,
                'error' => 'unreachable',
                'url' => $healthUrl,
                'body' => null,
            ];
        }

        return [
            'ok' => $statusCode >= 200 && $statusCode < 300,
            'status_code' => $statusCode,
            'error' => $statusCode >= 200 && $statusCode < 300 ? null : 'bad_status',
            'url' => $healthUrl,
            'body' => is_array($decoded) ? $decoded : $body,
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, string>
     */
    public function getMaskedAuthHeaders(Instance $instance, string $method, string $endpoint, array $query = []): array
    {
        $endpointWithQuery = $endpoint;
        if ($query !== []) {
            $queryString = http_build_query($query);
            $endpointWithQuery = $queryString !== '' ? $endpoint . '?' . $queryString : $endpoint;
        }

        $headers = $this->buildAuthHeaders($instance, $method, $endpointWithQuery);
        $masked = [];
        foreach ($headers as $name => $value) {
            $masked[$name] = $this->maskHeaderValue($value);
        }

        return $masked;
    }

    public function getBaseUrlForInstance(Instance $instance): string
    {
        return $this->resolveBaseUrl($instance->getNode());
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $json
     * @return array<string, mixed>
     */
    private function requestJson(Instance $instance, string $method, string $endpoint, array $query = [], ?array $json = null): array
    {
        $response = $this->requestRaw($instance, $method, $endpoint, $query, $json);
        try {
            $status = $response->getStatusCode();
        } catch (TransportExceptionInterface $exception) {
            throw new FileServiceException('filesvc_unreachable', 'File service unavailable.', 502, [], $exception);
        }
        if ($status >= 500) {
            throw new FileServiceException('filesvc_unreachable', 'File service unavailable.', 502, [
                'status_code' => $status,
            ]);
        }
        if ($status < 200 || $status >= 300) {
            try {
                $payload = $response->toArray(false);
            } catch (\Throwable $exception) {
                $body = $response->getContent(false);
                throw new FileServiceException('filesvc_error', 'File service error.', 502, [
                    'status_code' => $status,
                    'response_body' => $body,
                ], $exception);
            }
            $message = 'File service error.';
            if (is_array($payload)) {
                $errorField = $payload['error'] ?? null;
                if (is_string($errorField) && $errorField !== '') {
                    $message = $errorField;
                } elseif (is_array($errorField) && is_string($errorField['message'] ?? null) && $errorField['message'] !== '') {
                    $message = $errorField['message'];
                }
            }
            $errorCode = match ($status) {
                401, 403 => 'filesvc_unauthorized',
                404 => 'filesvc_not_found',
                default => 'filesvc_error',
            };
            throw new FileServiceException($errorCode, $message, 502, [
                'status_code' => $status,
                'response_body' => $payload,
            ]);
        }

        try {
            $payload = $response->toArray(false);
        } catch (\Throwable $exception) {
            throw new FileServiceException('filesvc_unreachable', 'File service unavailable.', 502, [], $exception);
        }
        return is_array($payload) ? $payload : [];
    }

    private function requestMultipart(Instance $instance, string $endpoint, FormDataPart $formData): void
    {
        $config = $this->buildRequestOptions($instance, 'POST', $endpoint, []);
        $options = $config['options'];
        $options['headers'] = array_merge($options['headers'], $formData->getPreparedHeaders()->toArray());
        $options['body'] = $formData->bodyToIterable();

        $response = $this->httpClient->request('POST', $this->resolveBaseUrl($instance->getNode()) . $config['endpoint'], $options);
        try {
            $status = $response->getStatusCode();
        } catch (TransportExceptionInterface $exception) {
            throw new \RuntimeException('File service unavailable.', 0, $exception);
        }
        if ($status >= 500) {
            throw new FileServiceException('filesvc_unreachable', 'File service unavailable.', 502, [
                'status_code' => $status,
            ]);
        }
        if ($status < 200 || $status >= 300) {
            try {
                $payload = $response->toArray(false);
            } catch (\Throwable $exception) {
                $body = $response->getContent(false);
                throw new FileServiceException('filesvc_error', 'File service error.', 502, [
                    'status_code' => $status,
                    'response_body' => $body,
                ], $exception);
            }
            $message = 'File service error.';
            if (is_array($payload)) {
                $errorField = $payload['error'] ?? null;
                if (is_string($errorField) && $errorField !== '') {
                    $message = $errorField;
                } elseif (is_array($errorField) && is_string($errorField['message'] ?? null) && $errorField['message'] !== '') {
                    $message = $errorField['message'];
                }
            }
            $errorCode = match ($status) {
                401, 403 => 'filesvc_unauthorized',
                404 => 'filesvc_not_found',
                default => 'filesvc_error',
            };
            throw new FileServiceException($errorCode, $message, 502, [
                'status_code' => $status,
                'response_body' => $payload,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $json
     */
    private function requestRaw(Instance $instance, string $method, string $endpoint, array $query = [], ?array $json = null): \Symfony\Contracts\HttpClient\ResponseInterface
    {
        $options = $this->buildRequestOptions($instance, $method, $endpoint, $query, $json);
        $baseUrl = $this->resolveBaseUrl($instance->getNode());
        $fullUrl = $baseUrl . $options['endpoint'];
        $this->assertTlsConfigured($baseUrl);
        $startedAt = microtime(true);

        $resolvedPath = $this->gameServerPathResolver->resolveRoot($instance);

        $this->logger->debug('filesvc.request', [
            'agent_id' => $instance->getNode()->getId(),
            'instance_id' => $instance->getId(),
            'customer_id' => $instance->getCustomer()->getId(),
            'resolved_path' => $resolvedPath,
            'method' => $method,
            'url' => $fullUrl,
            'timeout_seconds' => $this->timeoutSeconds,
        ]);

        try {
            $response = $this->httpClient->request($method, $fullUrl, $options['options']);
        } catch (TimeoutExceptionInterface $exception) {
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $this->logger->error('filesvc.transport_error', [
                'agent_id' => $instance->getNode()->getId(),
                'instance_id' => $instance->getId(),
                'customer_id' => $instance->getCustomer()->getId(),
                'method' => $method,
                'url' => $fullUrl,
                'timeout_seconds' => $this->timeoutSeconds,
                'duration_ms' => $durationMs,
                'error' => $exception->getMessage(),
            ]);
            throw new FileServiceException('filesvc_timeout', 'File service timed out.', 504, [], $exception);
        } catch (TransportExceptionInterface $exception) {
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $this->logger->error('filesvc.transport_error', [
                'agent_id' => $instance->getNode()->getId(),
                'instance_id' => $instance->getId(),
                'customer_id' => $instance->getCustomer()->getId(),
                'method' => $method,
                'url' => $fullUrl,
                'timeout_seconds' => $this->timeoutSeconds,
                'duration_ms' => $durationMs,
                'error' => $exception->getMessage(),
            ]);
            throw new FileServiceException('filesvc_unreachable', 'File service unavailable.', 502, [], $exception);
        }

        try {
            $status = $response->getStatusCode();
        } catch (TransportExceptionInterface $exception) {
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $this->logger->error('filesvc.status_error', [
                'agent_id' => $instance->getNode()->getId(),
                'instance_id' => $instance->getId(),
                'customer_id' => $instance->getCustomer()->getId(),
                'method' => $method,
                'url' => $fullUrl,
                'timeout_seconds' => $this->timeoutSeconds,
                'duration_ms' => $durationMs,
                'error' => $exception->getMessage(),
            ]);
            throw new FileServiceException('filesvc_unreachable', 'File service unavailable.', 502, [], $exception);
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        $this->logger->debug('filesvc.response', [
            'agent_id' => $instance->getNode()->getId(),
            'instance_id' => $instance->getId(),
            'customer_id' => $instance->getCustomer()->getId(),
            'method' => $method,
            'url' => $fullUrl,
            'status_code' => $status,
            'duration_ms' => $durationMs,
        ]);

        return $response;
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $json
     *
     * @return array{endpoint: string, options: array<string, mixed>}
     */
    private function buildRequestOptions(Instance $instance, string $method, string $endpoint, array $query = [], ?array $json = null): array
    {
        $endpointWithQuery = $endpoint;
        if ($query !== []) {
            $queryString = http_build_query($query);
            $endpointWithQuery = $queryString !== '' ? $endpoint . '?' . $queryString : $endpoint;
        }

        $headers = $this->buildAuthHeaders($instance, $method, $endpointWithQuery);
        $headers['Accept'] = 'application/json';
        try {
            $headers['X-Server-Root'] = $this->gameServerPathResolver->resolveRoot($instance);
        } catch (\RuntimeException $exception) {
            throw new FileServiceException('INVALID_SERVER_ROOT', 'Canonical server root is invalid or missing.', 422, [], $exception);
        }

        $options = [
            'headers' => $headers,
            'timeout' => $this->timeoutSeconds,
            'max_duration' => $this->timeoutSeconds,
            'connect_timeout' => min(3, $this->timeoutSeconds),
        ];

        if ($json !== null) {
            $options['json'] = $json;
        }

        $tlsOptions = $this->buildTlsOptions();
        if ($tlsOptions !== []) {
            $options = array_merge($options, $tlsOptions);
        }

        $requestId = $this->requestStack->getCurrentRequest()?->headers->get('X-Request-ID');
        if (is_string($requestId) && $requestId !== '') {
            $headers['X-Request-ID'] = $requestId;
        }

        $options['headers'] = $headers;

        return [
            'endpoint' => $endpointWithQuery,
            'options' => $options,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function buildAuthHeaders(Instance $instance, string $method, string $endpointWithQuery): array
    {
        $agent = $instance->getNode();
        $agentId = $agent->getId();
        $customerId = (string) $instance->getCustomer()->getId();
        $secret = $this->encryptionService->decrypt($agent->getSecretPayload());
        $timestamp = (new \DateTimeImmutable())->format(\DateTimeImmutable::RFC3339);

        $payload = sprintf(
            "%s\n%s\n%s\n%s\n%s",
            $agentId,
            $customerId,
            strtoupper($method),
            $endpointWithQuery,
            $timestamp,
        );
        $signature = hash_hmac('sha256', $payload, $secret);

        return [
            self::HEADER_AGENT_ID => $agentId,
            self::HEADER_CUSTOMER_ID => $customerId,
            self::HEADER_TIMESTAMP => $timestamp,
            self::HEADER_SIGNATURE => $signature,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTlsOptions(): array
    {
        $options = [];
        if ($this->clientCertPath !== '') {
            $options['local_cert'] = $this->clientCertPath;
        }
        if ($this->clientKeyPath !== '') {
            $options['local_pk'] = $this->clientKeyPath;
        }
        if ($this->clientCaPath !== '') {
            $options['cafile'] = $this->clientCaPath;
        }

        return $options;
    }

    private function resolveBaseUrl(Agent $node): string
    {
        $metadata = $node->getMetadata();
        $metadata = is_array($metadata) ? $metadata : [];

        $url = $metadata['filesvc_url'] ?? null;
        if (is_string($url) && $url !== '') {
            return rtrim($url, '/');
        }

        $host = $metadata['filesvc_host'] ?? null;
        if (!is_string($host) || $host === '') {
            $host = $node->getLastHeartbeatIp();
        }
        if (!is_string($host) || $host === '') {
            throw new FileServiceException('filesvc_misconfigured', 'File service host not configured.', 422);
        }

        $port = $metadata['filesvc_port'] ?? null;
        $port = is_numeric($port) ? (int) $port : $this->defaultPort;
        $scheme = $metadata['filesvc_scheme'] ?? null;
        $scheme = is_string($scheme) && $scheme !== '' ? $scheme : $this->defaultScheme;

        return sprintf('%s://%s:%d', $scheme, $host, $port);
    }

    private function buildEndpoint(Instance $instance, string $suffix): string
    {
        return sprintf('/v1/servers/%s%s', $instance->getId(), $suffix);
    }

    private function normalizeRelativePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = trim($path, '/');
        if ($path === '') {
            return '';
        }

        $segments = explode('/', $path);
        $safe = [];
        foreach ($segments as $segment) {
            $segment = trim($segment);
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                throw new \RuntimeException('Path traversal is not allowed.');
            }
            $safe[] = $segment;
        }

        return implode('/', $safe);
    }

    private function combinePath(string $path, string $name): string
    {
        $normalizedPath = $this->normalizeRelativePath($path);

        return $normalizedPath === '' ? $name : $normalizedPath . '/' . $name;
    }

    /**
     * @param array<string, array<int, string>> $headers
     */
    private function parseContentLength(array $headers): ?int
    {
        $lengthValues = $headers['content-length'] ?? null;
        if ($lengthValues === null || $lengthValues === []) {
            return null;
        }
        $length = $lengthValues[0] ?? null;
        if (!is_string($length) || !is_numeric($length)) {
            return null;
        }
        return (int) $length;
    }

    private function assertValidName(string $name): void
    {
        if ($name === '' || $name === '.' || $name === '..') {
            throw new \RuntimeException('Invalid name.');
        }
        if (str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, "\0")) {
            throw new \RuntimeException('Invalid name.');
        }
    }

    private function assertTlsConfigured(string $baseUrl): void
    {
        if (!str_starts_with($baseUrl, 'https://')) {
            return;
        }
        if ($this->clientCertPath !== '' && $this->clientKeyPath !== '' && $this->clientCaPath !== '') {
            return;
        }

        throw new FileServiceException('filesvc_misconfigured', 'File service TLS client certificates are not configured.', 422);
    }

    private function maskHeaderValue(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '<empty>';
        }
        if (strlen($value) <= 8) {
            return str_repeat('*', strlen($value));
        }
        return substr($value, 0, 4) . str_repeat('*', strlen($value) - 8) . substr($value, -4);
    }
}
